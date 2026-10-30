<?php

namespace Tests\Feature\Mobile\V1;

use App\Models\MobileDeviceSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Mobile\CapabilityResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The app's Devices screen = the web's Settings > Devices tab (web leads): it
 * needs settings.view, each phone says who signed in (name, mobile, IP), and
 * "Sign out this device" needs staff.manage, never on the owner's phone for a
 * non-owner. Login keeps the phone's name, clipped to the columns.
 */
class DevicesParityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function member($shop, string $name, array $permissions): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $name, $permissions) {
            $role = (new Role())->forceFill(['name' => $name, 'display_name' => ucfirst($name), 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

            return User::factory()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'is_active' => true, 'name' => ucfirst($name)]);
        });
    }

    private function phone(User $user, string $device): MobileDeviceSession
    {
        $token = $user->createToken('mobile-app');

        return MobileDeviceSession::withoutTenant()->create([
            'shop_id' => $user->shop_id, 'user_id' => $user->id, 'device_name' => $device,
            'platform' => 'android', 'app_version' => '1.4.0', 'ip_address' => '10.0.0.7',
            'token_id' => $token->accessToken->id, 'logged_in_at' => now(),
        ]);
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
        TenantContext::set((int) $user->shop_id);
    }

    public function test_login_keeps_the_phones_name_and_clips_overlong_values(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->postJson('/api/mobile/auth/login', [
            'mobile_number' => $owner->mobile_number, 'password' => 'password',
            'device_name' => 'Pixel 8 Pro', 'platform' => 'android', 'app_version' => '1.4.0', 'os_version' => '15',
        ])->assertOk();
        $row = MobileDeviceSession::withoutTenant()->where('user_id', $owner->id)->latest('id')->first();
        $this->assertSame(['Pixel 8 Pro', 'android', '1.4.0', '15'], [$row->device_name, $row->platform, $row->app_version, $row->os_version]);

        // A phone with a very long name must still sign in (the columns are 100 / 20 / 20 / 30).
        $this->postJson('/api/mobile/auth/login', [
            'mobile_number' => $owner->mobile_number, 'password' => 'password',
            'device_name' => str_repeat('N', 150), 'platform' => str_repeat('p', 30),
            'app_version' => str_repeat('9', 40), 'os_version' => str_repeat('1', 40),
        ])->assertOk();
        $row = MobileDeviceSession::withoutTenant()->where('user_id', $owner->id)->latest('id')->first();
        $this->assertSame(100, strlen($row->device_name));
        $this->assertSame(20, strlen($row->app_version));
    }

    public function test_the_list_needs_view_settings_like_the_web_tab(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $cashier = $this->member($shop, 'cashier', ['sales.pos']);
        $this->as($cashier);
        $this->getJson('/api/mobile/v1/sessions')->assertStatus(403);
        $this->assertFalse((new CapabilityResolver())->resolve($shop, $cashier)->devices);

        $viewer = $this->member($shop, 'viewer', ['settings.view']);
        $this->as($viewer);
        $this->getJson('/api/mobile/v1/sessions')->assertOk();
        $this->assertTrue((new CapabilityResolver())->resolve($shop, $viewer)->devices);
    }

    public function test_each_phone_says_who_signed_in_and_whether_you_may_sign_it_out(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $staff = $this->member($shop, 'staff', ['sales.pos']);
        $ownersPhone = $this->phone($owner, 'Owner phone');
        $staffPhone = $this->phone($staff, 'Counter phone');

        $manager = $this->member($shop, 'manager', ['settings.view', 'returns.approve', 'staff.manage']);
        $this->as($manager);
        $rows = collect($this->getJson('/api/mobile/v1/sessions')->assertOk()->json('data.data'))->keyBy('id');

        $this->assertSame('Staff', $rows[$staffPhone->id]['operator']['name']);
        $this->assertSame($staff->mobile_number, $rows[$staffPhone->id]['operator']['mobile']);
        $this->assertSame('10.0.0.7', $rows[$staffPhone->id]['ip_address']);
        $this->assertTrue($rows[$staffPhone->id]['can_sign_out']);
        $this->assertFalse($rows[$ownersPhone->id]['can_sign_out'], "a manager can't sign out the owner's phone");

        $this->as($owner);
        $rows = collect($this->getJson('/api/mobile/v1/sessions')->assertOk()->json('data.data'))->keyBy('id');
        $this->assertTrue($rows[$staffPhone->id]['can_sign_out']);
    }

    public function test_signing_out_a_phone_needs_manage_staff_like_the_web(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $staff = $this->member($shop, 'staff', ['sales.pos']);
        $staffPhone = $this->phone($staff, 'Counter phone');
        $ownersPhone = $this->phone($owner, 'Owner phone');

        // returns.approve sees every phone (as on the web) but may not sign one out.
        $approver = $this->member($shop, 'approver', ['settings.view', 'returns.approve']);
        $this->as($approver);
        $this->assertFalse(collect($this->getJson('/api/mobile/v1/sessions')->json('data.data'))->firstWhere('id', $staffPhone->id)['can_sign_out']);
        $this->as($approver); // each request ends the tenant context
        $this->withHeaders(['X-Idempotency-Key' => 'dev-a-' . uniqid()])
            ->deleteJson("/api/mobile/v1/sessions/{$staffPhone->id}")->assertStatus(403);
        $this->assertNull($staffPhone->fresh()->logged_out_at);

        $manager = $this->member($shop, 'manager', ['settings.view', 'staff.manage']);
        $this->as($manager);
        $this->withHeaders(['X-Idempotency-Key' => 'dev-m-' . uniqid()])
            ->deleteJson("/api/mobile/v1/sessions/{$ownersPhone->id}")->assertStatus(403);
        $this->as($manager);
        $this->withHeaders(['X-Idempotency-Key' => 'dev-s-' . uniqid()])
            ->deleteJson("/api/mobile/v1/sessions/{$staffPhone->id}")->assertOk();
        $this->assertSame('revoked', MobileDeviceSession::withoutTenant()->find($staffPhone->id)->ended_reason);
    }

    public function test_sessions_me_marks_this_phone_as_current(): void
    {
        [$owner] = $this->createRetailerTenant();
        $owner->createToken('web-other'); // so the token id and the session id differ
        $token = $this->postJson('/api/mobile/auth/login', [
            'mobile_number' => $owner->mobile_number, 'password' => 'password', 'device_name' => 'Pixel 8',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/mobile/v1/sessions/me')
            ->assertOk()->assertJsonPath('data.session.is_current', true);
    }

    public function test_the_web_tab_hides_sign_out_on_the_owners_phone_for_a_manager(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $staff = $this->member($shop, 'staff', ['sales.pos']);
        $ownersPhone = $this->phone($owner, 'Owner phone');
        $staffPhone = $this->phone($staff, 'Counter phone');
        $manager = $this->member($shop, 'manager', ['settings.view', 'returns.approve', 'staff.manage']);

        TenantContext::set($shop->id);
        $html = $this->actingAs($manager, 'web')->get(route('settings.edit', ['tab' => 'devices']))->assertOk()->getContent();

        $this->assertStringContainsString(route('settings.devices.destroy', $staffPhone), $html);
        $this->assertStringNotContainsString(route('settings.devices.destroy', $ownersPhone), $html);
    }
}

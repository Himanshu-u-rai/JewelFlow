<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\ScanSession;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScanSessionParallelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_creating_second_scan_session_does_not_expire_first(): void
    {
        [$user, $shop] = $this->createTenant();

        $first = $this->actingAs($user)->postJson('/scan-session/create')->assertOk()->json('token');
        $second = $this->actingAs($user)->postJson('/scan-session/create')->assertOk()->json('token');

        $this->assertNotSame($first, $second);
        $this->assertSame('active', ScanSession::where('token', $first)->value('status'));
        $this->assertSame('active', ScanSession::where('token', $second)->value('status'));
        $this->assertSame(2, ScanSession::where('shop_id', $shop->id)->where('status', 'active')->count());
    }

    public function test_parallel_scan_session_tokens_poll_and_post_independently(): void
    {
        [$user] = $this->createTenant();

        $first = $this->actingAs($user)->postJson('/scan-session/create')->assertOk()->json('token');
        $second = $this->actingAs($user)->postJson('/scan-session/create')->assertOk()->json('token');

        $this->postJson('/api/scan', [
            'token' => $first,
            'barcode' => 'PARALLEL-FIRST',
        ])->assertOk()->assertJsonPath('status', 'ok');

        $this->postJson('/api/scan', [
            'token' => $second,
            'barcode' => 'PARALLEL-SECOND',
        ])->assertOk()->assertJsonPath('status', 'ok');

        $this->actingAs($user)
            ->getJson("/scan-session/{$first}/poll")
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('barcodes.0', 'PARALLEL-FIRST');

        $this->actingAs($user)
            ->getJson("/scan-session/{$second}/poll")
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('barcodes.0', 'PARALLEL-SECOND');
    }

    private function createTenant(): array
    {
        $admin = PlatformAdmin::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'name' => 'Platform Admin',
            'email' => 'scan' . fake()->unique()->numberBetween(1000, 999999) . '@example.com',
            'mobile_number' => fake()->unique()->numerify('9#########'),
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'code' => 'scan-parallel-' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Scan Parallel Plan',
            'price_monthly' => 999,
            'grace_days' => 5,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
            'features' => ['staff_limit' => 3],
        ]);

        $shop = Shop::create([
            'name' => 'Scan Parallel Shop',
            'shop_type' => 'manufacturer',
            'phone' => '9000000000',
            'owner_first_name' => 'Owner',
            'owner_last_name' => 'Test',
            'owner_mobile' => fake()->unique()->numerify('9#########'),
            'gst_rate' => 3.00,
            'wastage_recovery_percent' => 100.00,
            'access_mode' => 'active',
            'is_active' => true,
        ]);

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'grace_ends_at' => now()->addDays(37)->toDateString(),
            'updated_by_admin_id' => $admin->id,
        ]);

        $role = new Role();
        $roleAttributes = [
            'name' => 'owner',
            'display_name' => 'Owner',
        ];
        if (Schema::hasColumn('roles', 'shop_id')) {
            $roleAttributes['shop_id'] = $shop->id;
        }
        $role->forceFill($roleAttributes);
        $role->save();

        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'mobile_number' => fake()->unique()->numerify('9#########'),
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        return [$user, $shop];
    }
}

<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Http\Middleware\EnsurePlatformAdminPasswordFresh;
use App\Models\Platform\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The platform's cross-shop views and actions sit behind one entry guard:
 * the platform_admin guard, an active account (`admin`), a session not
 * revoked by a password change (`admin.password.fresh`) and a second factor
 * cleared this session (`admin.mfa`). The legacy /super-admin URLs route to
 * the same controllers — every shop, every user, a shop's status, a user's
 * password — and must pass the same guard.
 */
class LegacyAdminRouteGuardTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const PASSWORD = 'Sup3r-Secret!1';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => 'Legacy', 'last_name' => 'Admin', 'name' => 'Legacy Admin',
            'email' => 'legacy@example.com', 'mobile_number' => '9000000002',
            'password' => Hash::make(self::PASSWORD), 'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(), 'password_changed_at' => now(),
        ]);
    }

    public function test_a_password_only_session_reaches_no_legacy_view_or_action(): void
    {
        $this->admin();
        [$owner, $shop] = $this->createRetailerTenant();
        $hash = $owner->fresh()->password;
        $this->post('/admin/login', ['mobile_number' => '9000000002', 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.mfa.show'));
        $this->assertNull(session(EnsurePlatformAdminMfa::SESSION_PASSED), 'the second factor is not cleared');

        $this->get('/admin/shops')->assertRedirect(route('admin.mfa.show'));   // control
        foreach (['/super-admin/shops', "/super-admin/shops/{$shop->id}", '/super-admin/users', "/super-admin/users/{$owner->id}"] as $url) {
            $this->get($url)->assertRedirect(route('admin.mfa.show'));
        }
        $this->patch("/super-admin/users/{$owner->id}/password", ['password' => 'Taken0ver!', 'password_confirmation' => 'Taken0ver!'])
            ->assertRedirect(route('admin.mfa.show'));
        $this->patch("/super-admin/shops/{$shop->id}/status", ['access_mode' => 'suspended', 'reason' => 'x'])
            ->assertRedirect(route('admin.mfa.show'));

        $this->assertSame($hash, $owner->fresh()->password, "a shop user's password");
        $this->assertSame('active', $shop->fresh()->access_mode, "a shop's status");
    }

    public function test_a_session_revoked_by_a_password_change_reaches_no_legacy_view(): void
    {
        $admin = $this->admin();
        [$owner] = $this->createRetailerTenant();
        $stamp = $admin->password_changed_at->getTimestamp();
        $admin->forceFill(['password_changed_at' => now()->addMinute()])->save();   // changed elsewhere since this session began

        foreach (['/admin/users', "/super-admin/users/{$owner->id}"] as $url) {
            $this->actingAs($admin->fresh(), 'platform_admin')
                ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true, EnsurePlatformAdminPasswordFresh::SESSION_KEY => $stamp])
                ->get($url)->assertRedirect(route('admin.login'));
        }
    }
}

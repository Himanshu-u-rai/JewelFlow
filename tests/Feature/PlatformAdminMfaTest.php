<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 2: platform-admin login requires a verified email + a second factor
 * (email OTP / TOTP) and supports single-use recovery codes.
 */
class PlatformAdminMfaTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const PASSWORD = 'Sup3r-Secret!1';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function admin(array $overrides = []): PlatformAdmin
    {
        return PlatformAdmin::create(array_merge([
            'first_name' => 'Mfa', 'last_name' => 'Admin', 'name' => 'Mfa Admin',
            'email' => 'mfa@example.com', 'mobile_number' => '9000000001',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(), 'password_changed_at' => now(),
        ], $overrides));
    }

    private function login(string $password = self::PASSWORD)
    {
        return $this->post('/admin/login', ['mobile_number' => '9000000001', 'password' => $password]);
    }

    private function putEmailOtp(PlatformAdmin $admin, string $otp = '123456'): void
    {
        Cache::put('admin-mfa-otp:' . $admin->id, Hash::make($otp), now()->addMinutes(10));
    }

    public function test_verified_password_redirects_to_mfa_not_dashboard(): void
    {
        $this->admin();
        $this->login()->assertRedirect(route('admin.mfa.show'));
        $this->assertAuthenticated('platform_admin');
        $this->assertNull(session(EnsurePlatformAdminMfa::SESSION_PASSED));
    }

    public function test_unverified_admin_redirected_to_email_verification(): void
    {
        $this->admin(['email_verified_at' => null]);
        $this->login()->assertRedirect(route('admin.verify-email'));
    }

    public function test_wrong_password_fails_before_otp(): void
    {
        $this->admin();
        $this->login('nope-wrong')->assertSessionHasErrors('mobile_number');
        $this->assertGuest('platform_admin');
    }

    public function test_correct_otp_completes_login(): void
    {
        $admin = $this->admin();
        $admin->generateRecoveryCodes(); // so success lands on dashboard, not the codes prompt
        $this->login();
        $this->putEmailOtp($admin);

        $this->post('/admin/mfa', ['otp' => '123456'])->assertRedirect(route('admin.dashboard'));
        $this->assertTrue(session(EnsurePlatformAdminMfa::SESSION_PASSED));
    }

    public function test_wrong_otp_fails(): void
    {
        $admin = $this->admin();
        $this->login();
        $this->putEmailOtp($admin, '123456');

        $this->post('/admin/mfa', ['otp' => '000000'])->assertSessionHasErrors('otp');
        $this->assertNotTrue(session(EnsurePlatformAdminMfa::SESSION_PASSED));
    }

    public function test_expired_otp_fails(): void
    {
        $admin = $this->admin();
        $this->login();
        $this->putEmailOtp($admin, '123456');
        Cache::forget('admin-mfa-otp:' . $admin->id); // TTL elapsed → code no longer valid

        $this->post('/admin/mfa', ['otp' => '123456'])->assertSessionHasErrors('otp');
    }

    public function test_otp_resend_is_rate_limited(): void
    {
        $this->admin();
        $this->login();

        $last = null;
        for ($i = 0; $i < 5; $i++) {
            $last = $this->post('/admin/mfa/resend');
        }
        $last->assertSessionHasErrors('otp');
    }

    public function test_protected_route_blocks_password_only_session(): void
    {
        $this->admin();
        $this->login();
        $this->get('/admin')->assertRedirect(route('admin.mfa.show'));
    }

    public function test_recovery_codes_are_shown_only_once(): void
    {
        $admin = $this->admin();
        $admin->generateRecoveryCodes();
        $this->login();
        $this->putEmailOtp($admin);
        $this->post('/admin/mfa', ['otp' => '123456']); // MFA passed

        $this->post('/admin/recovery-codes')->assertSessionHas('recovery_codes_plain');
        $plain = session('recovery_codes_plain');
        $this->assertCount(8, $admin->fresh()->recovery_codes);
        $this->assertNotContains($plain[0], $admin->fresh()->recovery_codes); // stored hashed, not plain

        $this->get('/admin/recovery-codes')->assertSee($plain[0]); // shown once (flash)
        $this->get('/admin/recovery-codes')->assertDontSee($plain[0]); // gone on next view
    }

    public function test_recovery_code_completes_challenge(): void
    {
        $admin = $this->admin();
        $plain = $admin->generateRecoveryCodes();
        $this->login();

        $this->post('/admin/mfa', ['recovery_code' => $plain[0]])->assertRedirect(route('admin.dashboard'));
        $this->assertTrue(session(EnsurePlatformAdminMfa::SESSION_PASSED));
        $this->assertCount(7, $admin->fresh()->recovery_codes); // consumed
    }

    public function test_used_recovery_code_cannot_be_reused(): void
    {
        $admin = $this->admin();
        $plain = $admin->generateRecoveryCodes();
        $this->login();
        $this->post('/admin/mfa', ['recovery_code' => $plain[0]]); // consume

        $this->post('/admin/logout');
        $this->login();
        $this->post('/admin/mfa', ['recovery_code' => $plain[0]])->assertSessionHasErrors('otp');
    }

    public function test_regenerating_recovery_codes_invalidates_old(): void
    {
        $admin = $this->admin();
        $old = $admin->generateRecoveryCodes();
        $new = $admin->generateRecoveryCodes();

        $this->assertFalse($admin->fresh()->useRecoveryCode($old[0]));
        $this->assertTrue($admin->fresh()->useRecoveryCode($new[0]));
    }

    public function test_logout_clears_mfa_marker(): void
    {
        $admin = $this->admin();
        $admin->generateRecoveryCodes();
        $this->login();
        $this->putEmailOtp($admin);
        $this->post('/admin/mfa', ['otp' => '123456']);
        $this->assertTrue(session(EnsurePlatformAdminMfa::SESSION_PASSED));

        $this->post('/admin/logout');
        $this->assertGuest('platform_admin');
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_password_reset_invalidates_old_mfa_session(): void
    {
        $admin = $this->admin();
        $admin->generateRecoveryCodes();
        $this->login();
        $this->putEmailOtp($admin);
        $this->post('/admin/mfa', ['otp' => '123456']);
        $this->get('/admin/recovery-codes')->assertOk(); // trusted session works

        // Simulate a password reset bumping password_changed_at (Phase 1 behaviour).
        $admin->forceFill(['password_changed_at' => now()->addDay()])->save();
        // Production reloads the admin per request; the test client caches the
        // guard, so drop it to force a fresh DB read (as a real request would).
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->get('/admin/recovery-codes')->assertRedirect(route('admin.login'));
    }

    public function test_shop_user_auth_is_untouched(): void
    {
        $this->assertSame('session', config('auth.guards.web.driver'));
        $this->assertSame('users', config('auth.guards.web.provider'));
        $this->assertSame('password_reset_tokens', config('auth.passwords.users.table'));
    }
}

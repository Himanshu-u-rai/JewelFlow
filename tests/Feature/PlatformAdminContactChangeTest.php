<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Mail\EmailOtpMail;
use App\Models\Platform\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Platform-admin email (recovery channel) and mobile (login id) changes are
 * staged + OTP-verified, gated by current password + an MFA-passed session.
 */
class PlatformAdminContactChangeTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const PASSWORD = 'Sup3r-Secret!1';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        Mail::fake();
    }

    private function admin(array $overrides = []): PlatformAdmin
    {
        return PlatformAdmin::create(array_merge([
            'first_name' => 'Acc', 'last_name' => 'Admin', 'name' => 'Acc Admin',
            'email' => 'acc@example.com', 'mobile_number' => '9000000001',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'super_admin', 'is_active' => true,
            // past stamp so a session-revoke bump (now()) is detectably greater
            'email_verified_at' => now(), 'password_changed_at' => now()->subDay(),
        ], $overrides));
    }

    /** Authenticated + MFA-passed session (what the route group requires). */
    private function asAdmin(PlatformAdmin $admin)
    {
        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    // ── access ────────────────────────────────────────────────────────────────

    public function test_cannot_change_email_without_login(): void
    {
        $this->post('/admin/account/email', ['current_password' => self::PASSWORD, 'new_email' => 'n@example.com'])
            ->assertRedirect(route('admin.login'));
    }

    public function test_cannot_change_email_without_mfa_session(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform_admin') // no MFA marker
            ->post('/admin/account/email', ['current_password' => self::PASSWORD, 'new_email' => 'n@example.com'])
            ->assertRedirect(route('admin.mfa.show'));
    }

    public function test_cannot_change_mobile_without_mfa_session(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform_admin')
            ->post('/admin/account/mobile', ['current_password' => self::PASSWORD, 'new_mobile' => '9111111111'])
            ->assertRedirect(route('admin.mfa.show'));
    }

    // ── email change ────────────────────────────────────────────────────────

    public function test_current_password_required_for_email_change(): void
    {
        $admin = $this->admin();
        $this->asAdmin($admin)->post('/admin/account/email', ['current_password' => 'wrong', 'new_email' => 'n@example.com'])
            ->assertSessionHasErrors('current_password');
        $this->assertNull($admin->fresh()->pending_email);
    }

    public function test_new_email_must_be_unique(): void
    {
        $admin = $this->admin();
        $this->admin(['email' => 'taken@example.com', 'mobile_number' => '9000000099']);
        $this->asAdmin($admin)->post('/admin/account/email', ['current_password' => self::PASSWORD, 'new_email' => 'taken@example.com'])
            ->assertSessionHasErrors('new_email');
    }

    public function test_email_is_staged_as_pending_first_and_otp_emailed(): void
    {
        $admin = $this->admin();
        $this->asAdmin($admin)->post('/admin/account/email', ['current_password' => self::PASSWORD, 'new_email' => 'new@example.com'])
            ->assertSessionHas('status');

        $fresh = $admin->fresh();
        $this->assertSame('new@example.com', $fresh->pending_email);
        $this->assertSame('acc@example.com', $fresh->email); // current stays primary until verified
        Mail::assertSent(EmailOtpMail::class);
    }

    public function test_otp_is_cached_hashed_not_plain(): void
    {
        $admin = $this->admin();
        $this->asAdmin($admin)->post('/admin/account/email', ['current_password' => self::PASSWORD, 'new_email' => 'new@example.com']);
        $cached = Cache::get('admin-email-change-otp:' . $admin->id);
        $this->assertNotNull($cached);
        $this->assertFalse(ctype_digit((string) $cached)); // a hash, not the 6-digit code
    }

    public function test_correct_otp_applies_email_and_revokes_sessions_and_audits(): void
    {
        $admin = $this->admin(['pending_email' => 'new@example.com']);
        Cache::put('admin-email-change-otp:' . $admin->id, Hash::make('123456'), now()->addMinutes(10));
        $before = $admin->password_changed_at->getTimestamp();

        $this->asAdmin($admin)->post('/admin/account/email/verify', ['otp' => '123456'])->assertSessionHas('status');

        $fresh = $admin->fresh();
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertNull($fresh->pending_email);
        $this->assertGreaterThan($before, $fresh->password_changed_at->getTimestamp()); // other sessions revoked
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform_admin.email_changed']);
    }

    public function test_wrong_otp_does_not_change_email(): void
    {
        $admin = $this->admin(['pending_email' => 'new@example.com']);
        Cache::put('admin-email-change-otp:' . $admin->id, Hash::make('123456'), now()->addMinutes(10));
        $this->asAdmin($admin)->post('/admin/account/email/verify', ['otp' => '000000'])->assertSessionHasErrors('otp');
        $this->assertSame('acc@example.com', $admin->fresh()->email);
    }

    public function test_expired_otp_fails(): void
    {
        $admin = $this->admin(['pending_email' => 'new@example.com']);
        Cache::put('admin-email-change-otp:' . $admin->id, Hash::make('123456'), now()->addMinutes(10));
        Cache::forget('admin-email-change-otp:' . $admin->id);
        $this->asAdmin($admin)->post('/admin/account/email/verify', ['otp' => '123456'])->assertSessionHasErrors('otp');
    }

    // ── mobile change ─────────────────────────────────────────────────────────

    public function test_new_mobile_must_be_unique(): void
    {
        $admin = $this->admin();
        $this->admin(['email' => 'b@example.com', 'mobile_number' => '9222222222']);
        $this->asAdmin($admin)->post('/admin/account/mobile', ['current_password' => self::PASSWORD, 'new_mobile' => '9222222222'])
            ->assertSessionHasErrors('new_mobile');
    }

    public function test_mobile_change_requires_verified_email(): void
    {
        $admin = $this->admin(['email_verified_at' => null]);
        // mfa middleware would bounce an unverified admin to verify-email; assert that gate.
        $this->asAdmin($admin)->post('/admin/account/mobile', ['current_password' => self::PASSWORD, 'new_mobile' => '9333333333'])
            ->assertRedirect(route('admin.verify-email'));
    }

    public function test_mobile_staged_then_applied_after_email_otp(): void
    {
        $admin = $this->admin();
        $this->asAdmin($admin)->post('/admin/account/mobile', ['current_password' => self::PASSWORD, 'new_mobile' => '9444444444'])
            ->assertSessionHas('status');
        $this->assertSame('9444444444', $admin->fresh()->pending_mobile);
        $this->assertSame('9000000001', $admin->fresh()->mobile_number); // unchanged until OTP
        Mail::assertSent(EmailOtpMail::class);

        Cache::put('admin-mobile-change-otp:' . $admin->id, Hash::make('654321'), now()->addMinutes(10));
        $this->asAdmin($admin)->post('/admin/account/mobile/verify', ['otp' => '654321'])->assertSessionHas('status');

        $fresh = $admin->fresh();
        $this->assertSame('9444444444', $fresh->mobile_number);
        $this->assertNull($fresh->pending_mobile);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform_admin.mobile_changed']);
    }

    // ── CLI ───────────────────────────────────────────────────────────────────

    public function test_cli_update_contact_applies_marks_unverified_revokes_and_audits(): void
    {
        $admin = $this->admin();
        $before = $admin->password_changed_at->getTimestamp();

        $this->artisan('platform-admin:update-contact', [
            'identifier' => 'acc@example.com', '--email' => 'cli@example.com', '--mobile' => '9555555555',
        ])->expectsConfirmation('Apply this contact change and sign out their other sessions?', 'yes')
            ->assertExitCode(0);

        $fresh = $admin->fresh();
        $this->assertSame('cli@example.com', $fresh->email);
        $this->assertSame('9555555555', $fresh->mobile_number);
        $this->assertNull($fresh->email_verified_at); // CLI email is unverified
        $this->assertGreaterThan($before, $fresh->password_changed_at->getTimestamp()); // sessions revoked
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform_admin.contact_updated_cli']);
    }

    public function test_cli_rejects_duplicate_email(): void
    {
        $admin = $this->admin();
        $this->admin(['email' => 'dupe@example.com', 'mobile_number' => '9666666666']);

        $this->artisan('platform-admin:update-contact', ['identifier' => 'acc@example.com', '--email' => 'dupe@example.com'])
            ->assertExitCode(1);
        $this->assertSame('acc@example.com', $admin->fresh()->email);
    }

    public function test_shop_user_auth_is_untouched(): void
    {
        $this->assertSame('session', config('auth.guards.web.driver'));
        $this->assertSame('users', config('auth.guards.web.provider'));
        $this->assertSame('password_reset_tokens', config('auth.passwords.users.table'));
    }
}

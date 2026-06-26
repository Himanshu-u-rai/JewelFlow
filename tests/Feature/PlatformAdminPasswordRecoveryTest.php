<?php

namespace Tests\Feature;

use App\Models\Platform\PlatformAdmin;
use App\Notifications\PlatformAdminResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class PlatformAdminPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function admin(array $overrides = []): PlatformAdmin
    {
        return PlatformAdmin::create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'mobile_number' => '9000000001',
            'password' => Hash::make('OldPassw0rd!!xx'),
            'role' => 'super_admin',
            'is_active' => true,
            'email_verified_at' => now(),
            'password_changed_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_verified_admin_receives_reset_link(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->post('/admin/forgot-password', ['email' => 'admin@example.com'])
            ->assertSessionHas('status');

        Notification::assertSentTo($admin, PlatformAdminResetPassword::class);
    }

    public function test_unknown_email_gets_generic_response_and_sends_nothing(): void
    {
        Notification::fake();

        $this->post('/admin/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_unverified_email_gets_no_reset_link(): void
    {
        Notification::fake();
        $this->admin(['email_verified_at' => null]);

        $this->post('/admin/forgot-password', ['email' => 'admin@example.com'])
            ->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_reset_updates_password_and_bumps_changed_at(): void
    {
        $admin = $this->admin();
        $token = Password::broker('platform_admins')->createToken($admin);
        $before = $admin->password_changed_at->getTimestamp();

        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => 'admin@example.com',
            'password' => 'BrandNewP@ss123',
            'password_confirmation' => 'BrandNewP@ss123',
        ])->assertRedirect(route('admin.login'));

        $admin->refresh();
        $this->assertTrue(Hash::check('BrandNewP@ss123', $admin->password));
        $this->assertGreaterThan($before, $admin->password_changed_at->getTimestamp());
    }

    public function test_expired_token_is_rejected(): void
    {
        $admin = $this->admin();
        $token = Password::broker('platform_admins')->createToken($admin);

        // Force the stored token past its 30-minute expiry window.
        \DB::table('platform_admin_password_reset_tokens')
            ->where('email', 'admin@example.com')
            ->update(['created_at' => now()->subHours(2)]);

        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => 'admin@example.com',
            'password' => 'BrandNewP@ss123',
            'password_confirmation' => 'BrandNewP@ss123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('OldPassw0rd!!xx', $admin->fresh()->password));
    }

    public function test_weak_password_is_rejected(): void
    {
        $admin = $this->admin();
        $token = Password::broker('platform_admins')->createToken($admin);

        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => 'admin@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('OldPassw0rd!!xx', $admin->fresh()->password));
    }

    public function test_email_verification_with_correct_and_wrong_otp(): void
    {
        $admin = $this->admin(['email_verified_at' => null]);
        Cache::put('admin-email-otp:' . $admin->id, Hash::make('123456'), now()->addMinutes(10));

        $this->actingAs($admin, 'platform_admin')
            ->post('/admin/verify-email/verify', ['otp' => '000000'])
            ->assertSessionHasErrors('otp');
        $this->assertNull($admin->fresh()->email_verified_at);

        // Cache the OTP again (wrong attempt did not consume it) and use the right one.
        Cache::put('admin-email-otp:' . $admin->id, Hash::make('123456'), now()->addMinutes(10));
        $this->actingAs($admin, 'platform_admin')
            ->post('/admin/verify-email/verify', ['otp' => '123456'])
            ->assertSessionHas('status');
        $this->assertNotNull($admin->fresh()->email_verified_at);
    }

    public function test_cli_reset_changes_password_and_writes_audit(): void
    {
        $admin = $this->admin();

        $this->artisan('platform-admin:reset-password', ['email' => 'admin@example.com'])
            ->expectsConfirmation('Continue?', 'yes')
            ->expectsQuestion('New password', 'CliBrandNew@1x')
            ->expectsQuestion('Confirm new password', 'CliBrandNew@1x')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('CliBrandNew@1x', $admin->fresh()->password));
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform_admin.password_reset_cli']);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/forgot-password', ['email' => 'admin@example.com']);
        }

        $this->post('/admin/forgot-password', ['email' => 'admin@example.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_shop_user_password_broker_is_unchanged(): void
    {
        // Platform-admin work must not touch the shop user reset broker.
        $this->assertSame('users', config('auth.passwords.users.provider'));
        $this->assertSame('password_reset_tokens', config('auth.passwords.users.table'));
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Platform\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Super Admin login POST path — the untested gap.
 *
 * Existing platform-admin tests authenticate via actingAs(..., 'platform_admin'),
 * which bypasses AuthController::login entirely. This exercises the real
 * /admin/login form submission end-to-end against the platform_admin guard,
 * plus the reset → login handoff (the reported "cannot log in even after reset").
 */
class PlatformAdminLoginTest extends TestCase
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

    /** 1. Valid credentials authenticate into the platform_admin guard. */
    public function test_valid_admin_logs_in_through_platform_admin_guard(): void
    {
        $this->admin();

        $response = $this->post('/admin/login', [
            'mobile_number' => '9000000001',
            'password' => 'OldPassw0rd!!xx',
        ]);

        // Password correct + email verified → routed to the MFA challenge, and
        // the guard is authenticated (pending MFA), NOT bounced back to login.
        $response->assertRedirect(route('admin.mfa.show'));
        $this->assertTrue(auth('platform_admin')->check(), 'admin must be authenticated on platform_admin guard');
    }

    /** 2. Invalid password is rejected and nothing authenticates. */
    public function test_invalid_password_is_rejected(): void
    {
        $this->admin();

        $response = $this->post('/admin/login', [
            'mobile_number' => '9000000001',
            'password' => 'WrongPassword!!1',
        ]);

        $response->assertSessionHasErrors('mobile_number');
        $this->assertFalse(auth('platform_admin')->check());
    }

    /** 3. Successful login regenerates the session (fixation defence). */
    public function test_successful_login_regenerates_session(): void
    {
        $this->admin();

        $this->startSession();
        $oldId = session()->getId();

        $this->post('/admin/login', [
            'mobile_number' => '9000000001',
            'password' => 'OldPassw0rd!!xx',
        ]);

        $this->assertNotSame($oldId, session()->getId(), 'session id must rotate on login');
    }

    /** 4. Suspended (inactive) admin stays blocked even with the right password. */
    public function test_inactive_admin_is_blocked(): void
    {
        // Second active super admin so the model guard allows this one to be inactive.
        $this->admin(['mobile_number' => '9000000009', 'email' => 'other@example.com']);
        $this->admin(['is_active' => false]);

        $response = $this->post('/admin/login', [
            'mobile_number' => '9000000001',
            'password' => 'OldPassw0rd!!xx',
        ]);

        $response->assertSessionHasErrors('mobile_number');
        $this->assertFalse(auth('platform_admin')->check());
    }

    /** 5 + 6. Reset updates the right account and the new password logs in immediately. */
    public function test_reset_password_then_login_with_new_password(): void
    {
        $admin = $this->admin();
        $token = Password::broker('platform_admins')->createToken($admin);

        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => 'admin@example.com',
            'password' => 'BrandNewP@ss123',
            'password_confirmation' => 'BrandNewP@ss123',
        ])->assertRedirect(route('admin.login'));

        // The updated row is the same one the guard resolves by mobile_number.
        $admin->refresh();
        $this->assertTrue(Hash::check('BrandNewP@ss123', $admin->password));

        $response = $this->post('/admin/login', [
            'mobile_number' => '9000000001',
            'password' => 'BrandNewP@ss123',
        ]);

        $response->assertRedirect(route('admin.mfa.show'));
        $this->assertTrue(auth('platform_admin')->check(), 'new password must authenticate at /admin/login');
    }

    /** 7. Normal shop-user (web guard) authentication is unaffected. */
    public function test_shop_user_web_login_still_works(): void
    {
        [$owner] = $this->createManufacturerTenant();
        // createOwnerUser hashes 'password' via the factory default.
        $this->assertTrue(Hash::check('password', $owner->password), 'sanity: shop owner password hash');

        $ok = auth('web')->attempt(['mobile_number' => $owner->mobile_number, 'password' => 'password'])
            || auth('web')->attempt(['email' => $owner->email, 'password' => 'password']);
        $this->assertTrue($ok, 'shop user must authenticate on the web guard');
        $this->assertFalse(auth('platform_admin')->check(), 'shop login must not leak into platform_admin guard');
    }

    /**
     * 8. Invalid credentials bounce back with a flashed generic error.
     *    (That the flashed error is then *visibly rendered* is proven by
     *    test_login_blade_renders_mobile_number_error below — the failing
     *    view path before this fix carried no @error block at all.)
     */
    public function test_invalid_credentials_flash_generic_error(): void
    {
        $this->admin();

        $response = $this->from('/admin/login')->post('/admin/login', [
            'mobile_number' => '9000000001',
            'password'      => 'WrongPassword!!1',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHasErrors(['mobile_number' => 'Invalid super admin credentials.']);
    }

    /** 9. The login Blade renders a mobile_number error inside an accessible alert. */
    public function test_login_blade_renders_mobile_number_error(): void
    {
        $view = $this->withViewErrors(['mobile_number' => 'Invalid super admin credentials.'])
            ->view('super-admin.auth.login', ['hasSuperAdmin' => true]);

        $view->assertSee('Invalid super admin credentials.');
        $view->assertSee('role="alert"', false);
    }

    /** 10. Rate-limit rejection flashes a throttle message on the mobile_number field. */
    public function test_rate_limit_flashes_throttle_message(): void
    {
        $this->admin();

        // 5 hits arm the limiter; the 6th trips tooManyAttempts() before the guard.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', [
                'mobile_number' => '9000000001',
                'password'      => 'WrongPassword!!1',
            ]);
        }

        $response = $this->from('/admin/login')->post('/admin/login', [
            'mobile_number' => '9000000001',
            'password'      => 'WrongPassword!!1',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHasErrors('mobile_number');
        $this->assertStringContainsString(
            'Too many',
            session('errors')->first('mobile_number'),
            'throttle message must be flashed so the view can display it'
        );
    }

    /** 11. Error text is identical for unknown mobile vs wrong password — no account enumeration. */
    public function test_error_message_does_not_leak_account_existence(): void
    {
        $this->admin(); // exists: 9000000001

        // Wrong password on a real account.
        $this->from('/admin/login')->post('/admin/login', [
            'mobile_number' => '9000000001',
            'password'      => 'WrongPassword!!1',
        ])->assertSessionHasErrors(['mobile_number' => 'Invalid super admin credentials.']);

        // A mobile number that maps to no account at all — identical generic message.
        $this->from('/admin/login')->post('/admin/login', [
            'mobile_number' => '9000000002',
            'password'      => 'WrongPassword!!1',
        ])->assertSessionHasErrors(['mobile_number' => 'Invalid super admin credentials.']);
    }
}

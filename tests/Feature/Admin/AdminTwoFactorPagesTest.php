<?php

namespace Tests\Feature\Admin;

use App\Models\Platform\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * /admin/2fa/manage used to 500 for every admin ("Undefined variable $slot"): its two views @extended a
 * component layout. The pages must render, and enrolling/disabling must keep needing the admin session and the
 * admin's own code or password.
 */
class AdminTwoFactorPagesTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Traits\CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    /** Signed in and through the MFA gate, as the routes require (admin, admin.password.fresh, admin.mfa). */
    private function as(PlatformAdmin $admin): static
    {
        return $this->actingAs($admin, 'platform_admin')
            ->withSession([\App\Http\Middleware\EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    private function admin(bool $twoFactor = false): PlatformAdmin
    {
        $admin = PlatformAdmin::create([
            'first_name' => 'Two', 'last_name' => 'Factor', 'name' => 'Two Factor',
            'email' => 'tf' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => Hash::make('right-password'),
            'role' => 'super_admin', 'is_active' => true, 'email_verified_at' => now(),
        ]);
        if ($twoFactor) {
            $admin->forceFill(['two_factor_enabled' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        }

        return $admin;
    }

    public function test_setup_page_renders_for_an_admin_without_two_factor(): void
    {
        $this->as($this->admin())
            ->get(route('admin.2fa.enroll'))
            ->assertOk()
            ->assertSee('Set Up Two-Factor Authentication')
            ->assertSee('name="otp"', false);
    }

    public function test_settings_page_renders_for_an_admin_with_two_factor(): void
    {
        $this->as($this->admin(true))
            ->get(route('admin.2fa.enroll'))
            ->assertOk()
            ->assertSee('Disable 2FA');
    }

    public function test_guests_cannot_open_or_change_two_factor(): void
    {
        $this->get(route('admin.2fa.enroll'))->assertRedirect();
        $this->post(route('admin.2fa.enroll.confirm'), ['otp' => '123456'])->assertRedirect();
        $this->post(route('admin.2fa.disable'), ['password' => 'x'])->assertRedirect();
    }

    public function test_wrong_code_does_not_enable_and_wrong_password_does_not_disable(): void
    {
        $plain = $this->admin();
        $this->as($plain)->get(route('admin.2fa.enroll'))->assertOk();
        $this->as($plain)->post(route('admin.2fa.enroll.confirm'), ['otp' => '000000']);
        $this->assertFalse((bool) $plain->fresh()->two_factor_enabled);

        $enrolled = $this->admin(true);
        $this->as($enrolled)->post(route('admin.2fa.disable'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
        $this->assertTrue((bool) $enrolled->fresh()->two_factor_enabled);

        $this->as($enrolled)->post(route('admin.2fa.disable'), ['password' => 'right-password']);
        $this->assertFalse((bool) $enrolled->fresh()->two_factor_enabled);
    }
}

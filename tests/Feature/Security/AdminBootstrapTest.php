<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-21 — /admin/register creates the first super admin, once. Concurrent
 * first registrations are measured in tests/Concurrency/admin_bootstrap_race.php
 * (separate processes); these cover the sequential contract around it:
 * the first registration, the refusal once configured (the form and the
 * POST), and the management flow, which still adds super admins — the
 * bootstrap is serialized, not limited to one super admin.
 */
class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;   // skipIfNotPostgres()

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function registration(string $mobile): array
    {
        return ['first_name' => 'Boot', 'last_name' => 'Strap', 'mobile_number' => $mobile, 'password' => 'Bootstrap-Pass-1', 'password_confirmation' => 'Bootstrap-Pass-1'];
    }

    private function superAdmin(): PlatformAdmin
    {
        return PlatformAdmin::create(['first_name' => 'First', 'last_name' => 'Admin', 'name' => 'First Admin', 'email' => 'first@example.com',
            'mobile_number' => '9876500100', 'password' => Hash::make('password'), 'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(), 'password_changed_at' => now()]);
    }

    public function test_the_first_registration_creates_the_super_admin(): void
    {
        $this->get('/admin/register')->assertOk();
        $this->post('/admin/register', $this->registration('9876500101'))->assertRedirect(route('admin.dashboard'));

        $this->assertSame(1, PlatformAdmin::where('role', 'super_admin')->count());
        $this->assertAuthenticated('platform_admin');
        $this->assertTrue(PlatformAuditLog::where('action', 'platform_admin.created')->exists());
    }

    public function test_once_configured_the_form_and_the_registration_are_refused(): void
    {
        $this->superAdmin();

        $this->get('/admin/register')->assertRedirect(route('admin.login'));
        $this->post('/admin/register', $this->registration('9876500102'))
            ->assertRedirect(route('admin.login'))->assertSessionHasErrors('mobile_number');

        $this->assertSame(1, PlatformAdmin::count());
        $this->assertGuest('platform_admin');
    }

    public function test_a_super_admin_can_still_add_another_super_admin_through_management(): void
    {
        $actor = $this->superAdmin();

        $this->actingAs($actor, 'platform_admin')->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->post(route('admin.platform-admins.store'), $this->registration('9876500103') + ['role' => 'super_admin'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PlatformAdmin::where('role', 'super_admin')->count());
    }
}

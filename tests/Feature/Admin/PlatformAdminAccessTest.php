<?php

namespace Tests\Feature\Admin;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformImpersonationSession;
use App\Models\User;
use App\Services\AdminImpersonationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Platform Admin — feature / security (Module 4). P0 high-risk: guard separation,
 * the super-admin role gate, and impersonation lifecycle/audit/scope.
 */
class PlatformAdminAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function admin(string $role = 'super_admin', bool $active = true): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => 'P', 'last_name' => 'A', 'name' => 'PA',
            'email' => 'a' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => Hash::make('password'),
            'role' => $role, 'is_active' => $active,
        ]);
    }

    // ── Login / logout flow ────────────────────────────────────────────────

    public function test_admin_login_page_renders(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_valid_admin_login_reaches_dashboard(): void
    {
        $admin = $this->admin();

        $res = $this->post('/admin/login', [
            'mobile_number' => $admin->mobile_number, 'password' => 'password',
        ]);

        $res->assertRedirect(route('admin.dashboard'));
        $this->assertTrue(auth('platform_admin')->check());
    }

    public function test_invalid_admin_login_fails(): void
    {
        $admin = $this->admin();

        $res = $this->post('/admin/login', [
            'mobile_number' => $admin->mobile_number, 'password' => 'wrong-password',
        ]);

        $res->assertSessionHasErrors('mobile_number');
        $this->assertFalse(auth('platform_admin')->check());
    }

    public function test_inactive_admin_cannot_log_in(): void
    {
        $admin = $this->admin(active: false);

        $this->post('/admin/login', ['mobile_number' => $admin->mobile_number, 'password' => 'password']);

        $this->assertFalse(auth('platform_admin')->check());
    }

    public function test_admin_dashboard_renders_for_authed_admin(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform_admin')->get(route('admin.dashboard'))->assertOk();
    }

    public function test_guest_admin_page_redirects_to_admin_login(): void
    {
        $res = $this->get(route('admin.dashboard'));
        $res->assertRedirect(route('admin.login'));
    }

    // ── Guard separation (P0) ──────────────────────────────────────────────

    public function test_shop_owner_cannot_access_admin_routes(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner) // web guard
            ->get(route('admin.dashboard')));

        $res->assertRedirect(route('admin.login')); // web auth is not platform_admin auth
    }

    public function test_admin_login_does_not_authenticate_web_guard(): void
    {
        $admin = $this->admin();
        $this->post('/admin/login', ['mobile_number' => $admin->mobile_number, 'password' => 'password']);

        $this->assertTrue(auth('platform_admin')->check());
        $this->assertFalse(auth('web')->check(), 'platform admin login must not set the shop web guard');
    }

    // ── Super-admin role gate ──────────────────────────────────────────────

    public function test_non_super_admin_is_blocked_from_super_admin_route(): void
    {
        $support = $this->admin('support');

        $res = $this->actingAs($support, 'platform_admin')->get(route('admin.security.index'));
        $res->assertForbidden(); // platform.role:super_admin
    }

    public function test_super_admin_reaches_super_admin_route(): void
    {
        $admin = $this->admin('super_admin');
        $this->actingAs($admin, 'platform_admin')->get(route('admin.security.index'))->assertOk();
    }

    // ── Impersonation lifecycle / audit / scope ────────────────────────────

    public function test_super_admin_can_start_and_stop_impersonation_with_audit(): void
    {
        $admin = $this->admin('super_admin');
        [$owner, $shop] = $this->createManufacturerTenant();

        $svc = app(AdminImpersonationService::class);

        // Start: one open session, web guard now the impersonated user, audited.
        $this->actingAs($admin, 'platform_admin');
        $session = $svc->start($admin, $owner);

        $this->assertNull($session->ended_at);
        $this->assertSame($owner->id, $session->user_id);
        $this->assertSame($shop->id, $session->shop_id);
        $this->assertTrue(PlatformAuditLog::where('action', 'admin.impersonation_started')
            ->where('target_id', $owner->id)->exists());

        // Stop: session closed exactly once, web guard logged out, audited.
        $svc->stop($session, $admin, 'manual');
        $this->assertNotNull($session->fresh()->ended_at);
        $this->assertFalse(auth('web')->check(), 'web guard cleared after stop');
        $this->assertTrue(PlatformAuditLog::where('action', 'admin.impersonation_ended')
            ->where('target_id', $owner->id)->exists());
    }

    public function test_non_super_admin_cannot_start_impersonation_via_route(): void
    {
        $support = $this->admin('support');
        [$owner, $shop] = $this->createManufacturerTenant();

        // Route is inside platform.role:super_admin → a support admin is 403/blocked.
        $res = $this->actingAs($support, 'platform_admin')
            ->post(route('admin.shops.impersonate', $shop));

        $this->assertContains($res->getStatusCode(), [403, 302]);
        $this->assertSame(0, PlatformImpersonationSession::count(), 'no session created by a non-super admin');
    }

    public function test_stopping_impersonation_does_not_leave_an_open_session(): void
    {
        $admin = $this->admin('super_admin');
        [$owner] = $this->createManufacturerTenant();

        $this->actingAs($admin, 'platform_admin');
        $svc = app(AdminImpersonationService::class);
        $session = $svc->start($admin, $owner);
        $svc->stop($session, $admin, 'manual');

        // No open (ended_at null) session remains for this user.
        $this->assertSame(0, PlatformImpersonationSession::whereNull('ended_at')->count());
    }

    // ── Shop status toggle isolation ───────────────────────────────────────

    public function test_admin_shop_status_toggle_affects_only_the_target_shop(): void
    {
        $admin = $this->admin('super_admin');
        [, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();

        $this->actingAs($admin, 'platform_admin')
            ->patch(route('admin.shops.status', $shopA), ['access_mode' => 'suspended', 'reason' => 'test']);

        // Only shop A changed; shop B untouched.
        $this->assertFalse((bool) $shopA->fresh()->is_active);
        $this->assertTrue((bool) $shopB->fresh()->is_active, 'sibling shop must be unaffected');
    }
}

<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Reports / Exports (Module 17). The reporting framework is exhaustively covered
 * (~260 tests: every screen renders, per-report shop-scoping with cross-shop
 * "noise" rows, CSV/Excel/PDF renderers, column policy, filter/date resolution,
 * export download authz incl. foreign-tenant + sensitive-permission). This adds
 * the one untested slice: the access gates on the report hub, the generic report
 * screen, and the export hub — guest → login and missing-permission → 403.
 */
class ReportsAccessGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    // ── Guest gating ────────────────────────────────────────────────────────

    public function test_guest_cannot_reach_report_hub(): void
    {
        $res = $this->get(self::ERP . '/reports');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    public function test_guest_cannot_reach_report_screen(): void
    {
        $res = $this->get(self::ERP . '/reporting/gst/screen');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    public function test_guest_cannot_reach_export_hub(): void
    {
        $res = $this->get(self::ERP . '/export');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    // ── Permission gating ─────────────────────────────────────────────────

    public function test_user_without_reports_view_is_denied_the_hub(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9823300001'); // no reports.*

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/reports'))->assertForbidden();
    }

    public function test_user_without_reports_view_is_denied_a_report_screen(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9823300002');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/reporting/gst/screen'))->assertForbidden();
    }

    public function test_user_without_reports_export_is_denied_the_export_hub(): void
    {
        [, $shop] = $this->createRetailerTenant();
        // Has reports.view but NOT reports.export — the export hub is gated separately.
        $viewer = $this->userWithPerms($shop, ['reports.view'], '9823300003');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/export'))->assertForbidden();
    }

    // ── Authorized render ───────────────────────────────────────────────────

    public function test_reports_view_user_can_open_hub_and_screen(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $viewer = $this->userWithPerms($shop, ['reports.view'], '9823300004');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/reports'))->assertOk();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/reporting/gst/screen'))->assertOk();
    }
}

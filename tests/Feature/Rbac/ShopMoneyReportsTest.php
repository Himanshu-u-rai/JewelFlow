<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\Reporting\Definition\ColumnType;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Owner decision 2026-11-07: staff must not see what the shop earns. "Everyday reports" (reports.view) opens the
 * Reports page and the money-free reports; every report showing rupee figures also needs "See shop money"
 * (reports.money).
 */
class ShopMoneyReportsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
        [, $this->shop] = $this->createRetailerTenant();
        ShopSubscription::query()->where('shop_id', $this->shop->id)->update(['status' => 'active']);
    }

    private function userWith(array $permissions, string $mobile): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'r'.$mobile, 'display_name' => 'R', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id, 'is_active' => true, 'mobile_number' => $mobile]);
    }

    private function get_(string $url)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->get($url));
    }

    public function test_every_report_with_rupee_columns_needs_the_money_switch(): void
    {
        // Daily Closing has its own switch; the two staff-monitoring reports are owner/manager-only (reports.audit).
        $ownSwitch = ['daily-closing', 'operator-performance'];
        $registry = app(ReportRegistry::class);

        foreach ($registry->keys() as $key) {
            $def = $registry->definition($key);
            $hasMoney = collect($def->columns)->contains(fn ($c) => $c->type === ColumnType::Money);
            if (! $hasMoney || in_array($key, $ownSwitch, true)) {
                continue;
            }
            $this->assertSame('reports.money', $def->permissions->effectiveViewGate(), "{$key} shows rupee figures without the money switch");
        }
    }

    public function test_everyday_reports_user_sees_money_free_reports_only(): void
    {
        $staff = $this->userWith(['reports.view'], '9810001001');
        $this->actingAs($staff);

        $hub = $this->get_('/reports')->assertOk();
        $hub->assertSee(route('report.gold'), false);
        foreach (['report.pnl', 'report.sales-register', 'report.daily', 'report.gstr1', 'report.repairs', 'report.dead-stock', 'report.sellers', 'report.operator-performance'] as $name) {
            $hub->assertDontSee(route($name), false);
            $this->assertSame(403, $this->get_(route($name, [], false))->status(), "{$name} opened without the money switch");
        }
        $this->get_('/report/gold')->assertOk();
    }

    public function test_shop_wide_totals_need_the_money_switch(): void
    {
        $perms = ['reports.view', 'quick_bills.view', 'installments.view', 'karigar_invoice.view'];
        $pages = ['/quick-bills' => "Today's sales", '/installments' => 'Collected This Month', '/karigar-invoices' => 'This page value'];

        $this->actingAs($this->userWith($perms, '9810001003'));
        foreach ($pages as $url => $label) {
            $this->get_($url)->assertOk()->assertDontSee($label, false);
        }

        $this->actingAs($this->userWith([...$perms, 'reports.money'], '9810001004'));
        foreach ($pages as $url => $label) {
            $this->get_($url)->assertOk()->assertSee($label, false);
        }
    }

    public function test_money_switch_opens_the_money_reports(): void
    {
        $this->actingAs($this->userWith(['reports.view', 'reports.money'], '9810001002'));

        $this->get_('/reports')->assertOk()->assertSee(route('report.pnl'), false);
        $this->get_('/report/pnl')->assertOk();
        $this->get_('/report/sales-register')->assertOk();
    }
}

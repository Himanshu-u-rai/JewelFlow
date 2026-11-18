<?php

namespace Tests\Feature\FinancialYear\Reports;

use App\Models\Permission;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** The three year reports on the Reports hub and at /report/{key}: who may open them, and that the screen renders. */
class YearReportsAccessTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, BuildsYearReports;

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
        $role->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id, 'is_active' => true, 'mobile_number' => $mobile]);
    }

    private function get_(string $url)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->get($url));
    }

    private const FY = 'date_preset=named_fy&fy_name=2025-26';

    public function test_money_reports_refuse_staff_without_the_money_switch_but_the_weight_register_opens(): void
    {
        $this->actingAs($this->userWith(['reports.view'], '9820001001'));
        $this->assertSame(403, $this->get_('/report/closing-stock?' . self::FY)->status());
        $this->assertSame(403, $this->get_('/report/trading-account?' . self::FY)->status());
        $this->get_('/report/stock-register-weight?' . self::FY)->assertOk();

        $hub = $this->get_('/reports')->assertOk();
        $hub->assertDontSee(route('report.closing-stock'), false);
        $hub->assertDontSee(route('report.trading-account'), false);
        $hub->assertSee(route('report.stock-register-weight'), false);
    }

    public function test_with_the_money_switch_all_three_render_on_screen_and_sit_on_the_hub(): void
    {
        $this->actingAs($this->userWith(['reports.view', 'reports.money'], '9820001002'));
        $hub = $this->get_('/reports')->assertOk();
        foreach (['report.closing-stock', 'report.stock-register-weight', 'report.trading-account'] as $name) {
            $hub->assertSee(route($name), false);
        }
        $this->at('2025-06-01 10:00:00');
        $this->goldItem($this->shop->id, 'SCR-1', 1000);
        $this->today();
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22, 6000);

        $closing = $this->get_('/report/closing-stock?' . self::FY)->assertOk();
        $closing->assertSee('SCR-1');
        $closing->assertSee('Live — no snapshot yet');
        $this->get_('/report/stock-register-weight?' . self::FY)->assertOk()->assertSee('Closing stock (actual)');
        $this->get_('/report/trading-account?' . self::FY)->assertOk()->assertSee('Cost of goods sold');
    }

    public function test_csv_and_excel_exports_render_with_the_source_note(): void
    {
        $this->at('2025-06-01 10:00:00');
        $this->goldItem($this->shop->id, 'EXP-1', 1000);
        $this->today();
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22, 6000);
        $period = app(\App\Services\Reporting\Filters\FilterResolver::class)->namedFy('2025-26');

        foreach (['closing-stock', 'stock-register-weight', 'trading-account'] as $key) {
            foreach ([\App\Services\Reporting\Definition\ExportFormat::Csv, \App\Services\Reporting\Definition\ExportFormat::Excel] as $format) {
                $request = new \App\Services\Reporting\Dataset\ReportRequest(
                    definition: app(ReportRegistry::class)->definition($key), shopId: $this->shop->id, userId: 1, userName: 'Owner',
                    profile: \App\Services\Reporting\Definition\ReportProfile::Detailed, format: $format,
                    filters: ['period' => ['from' => $period->from, 'to' => $period->to]], columnKeys: $this->keysFor($key, true),
                );
                $meta = new \App\Services\Reporting\Dataset\ReportMeta(
                    reportKey: $key, reportVersion: $request->definition->version, title: $request->definition->title, profileLabel: 'Detailed',
                    format: $format->value, filtersApplied: ['Period' => $period->label], periodLabel: $period->label, shopLegalName: 'Goldlux',
                    shopAddress: null, shopGstin: null, shopStateCode: null, generatedByName: 'Owner', generatedAt: now(), generatorTag: 'test',
                );
                $result = TenantContext::runFor($this->shop->id, fn () => app(\App\Services\Reporting\ExportPipeline::class)->run($request, $meta));
                $this->assertGreaterThan(0, $result->output->byteSize(), "{$key} {$format->value}");
            }
        }
    }

    public function test_registered_definitions_follow_the_money_gate_rule(): void
    {
        $registry = app(ReportRegistry::class);
        foreach (['closing-stock', 'trading-account'] as $key) {
            $this->assertSame('reports.money', $registry->definition($key)->permissions->effectiveViewGate(), $key);
            $this->assertSame('CONFIDENTIAL', $registry->definition($key)->watermarkBaseline, $key);
        }
        $this->assertTrue($registry->has('stock-register-weight'));
    }
}

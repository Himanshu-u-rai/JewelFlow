<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Reports\DeadStockDataset;
use App\Services\Reporting\Reports\OperatorPerformanceDataset;
use App\Services\Reporting\Reports\SuspiciousActivityDataset;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * GAP 2 migration: operator-performance, suspicious-activity and dead-stock now
 * run through the reporting spine (generic screen + gated export), each wrapping
 * its canonical service verbatim. GAP 3: their legacy fputcsv routes + the
 * redundant inventory-valuation CSV route are retired.
 */
class Gap2MigrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function period(): array
    {
        return ['period' => [
            'from' => CarbonImmutable::parse('2026-04-01'),
            'to' => CarbonImmutable::parse('2026-06-30'),
        ]];
    }

    private function makeRequest(string $datasetClass, int $shopId, array $columnKeys): ReportRequest
    {
        $definition = app($datasetClass)->definition();

        return new ReportRequest(
            definition: $definition,
            shopId: $shopId,
            userId: null,
            userName: 'Tester',
            profile: ReportProfile::Detailed,
            format: ExportFormat::Csv,
            filters: $this->period(),
            columnKeys: $columnKeys,
        );
    }

    private function restrictTo(User $user, array $names): void
    {
        $ids = Permission::whereIn('name', $names)->pluck('id');
        TenantContext::runFor((int) $user->shop_id, fn () => $user->role->permissions()->sync($ids));
        $user->unsetRelation('role');
    }

    private function makeRoleUser(int $shopId, string $roleName, array $perms): User
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $roleName, $perms) {
            $role = (new Role())->forceFill(['name' => $roleName, 'display_name' => ucfirst($roleName), 'shop_id' => $shopId]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::factory()->create(['shop_id' => $shopId, 'role_id' => $role->id, 'is_active' => true]);
        });
    }

    // 1. All three keys are registered on the spine.
    public function test_three_reports_registered(): void
    {
        $registry = app(ReportRegistry::class);
        foreach (['operator-performance', 'suspicious-activity', 'dead-stock'] as $key) {
            $this->assertTrue($registry->has($key), "{$key} should be registered");
        }
    }

    // 2. Operator performance builds and reconciles to AuditService totals.
    public function test_operator_performance_builds_and_reconciles(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $service = app(\App\Reporting\AuditService::class);
            $svcData = $service->operatorPerformance($shop->id, \App\Reporting\ReportPeriod::range('2026-04-01', '2026-06-30'));

            $request = $this->makeRequest(OperatorPerformanceDataset::class, $shop->id,
                ['operator', 'invoices', 'sales', 'discount', 'returns', 'returns_value', 'net_sales']);
            $dataset = app(OperatorPerformanceDataset::class)->build($request, $this->stubMeta());

            $section = $dataset->sections[0];
            $this->assertSame($svcData->rows->count(), count($section->rows));
            // Section Σ sales reconciles to the service total (reconcile by construction).
            $this->assertEqualsWithDelta($svcData->totalSales, (float) ($section->totals['sales'] ?? 0), 0.01);
        });
    }

    // 3. Suspicious activity builds (empty period is valid: zero alerts).
    public function test_suspicious_activity_builds(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $request = $this->makeRequest(SuspiciousActivityDataset::class, $shop->id, ['type', 'customer', 'invoice', 'date', 'resolved']);
            $dataset = app(SuspiciousActivityDataset::class)->build($request, $this->stubMeta());
            $this->assertCount(1, $dataset->sections); // alerts section always present
        });
    }

    // 4. Dead stock builds both sections; the cost column is sensitive.
    public function test_dead_stock_builds_and_cost_is_sensitive(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $request = $this->makeRequest(DeadStockDataset::class, $shop->id, ['bucket', 'count', 'value', 'barcode', 'design', 'age_days', 'cost']);
            $dataset = app(DeadStockDataset::class)->build($request, $this->stubMeta());
            $this->assertCount(2, $dataset->sections); // by_age + oldest

            $costCol = app(DeadStockDataset::class)->definition()->column('cost');
            $this->assertNotNull($costCol);
            $this->assertSame(\App\Services\Reporting\Definition\ColumnTier::Sensitive, $costCol->tier);
        });
    }

    // 5. Audit reports require the owner/manager surface gate (reports.audit).
    public function test_audit_report_blocks_plain_viewer(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        // A plain viewer: view + export but NOT the audit surface gate.
        $this->restrictTo($owner, ['reports.view', 'reports.export']);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/report/operator-performance'))
            ->assertForbidden();
    }

    // 6. An owner/manager with reports.audit can view the migrated audit screen.
    public function test_audit_report_allows_audit_role(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $manager = $this->makeRoleUser($shop->id, 'manager', ['reports.view', 'reports.export', 'reports.audit']);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($manager)
            ->get('/report/operator-performance'))
            ->assertOk();
    }

    // 7. Dead-stock (Operational) screen renders for a plain viewer.
    public function test_dead_stock_screen_renders(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->restrictTo($owner, ['reports.view']);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/report/dead-stock'))
            ->assertOk();
    }

    // 8. The retired legacy CSV routes no longer exist.
    public function test_legacy_csv_routes_retired(): void
    {
        foreach ([
            'report.dead-stock.csv',
            'report.operator-performance.csv',
            'report.suspicious-activity.csv',
            'report.inventory-valuation.csv',
        ] as $name) {
            $this->assertFalse(\Illuminate\Support\Facades\Route::has($name), "{$name} should be retired");
        }
    }

    // 9. The spine export route serves these reports (gated export replaces fputcsv).
    public function test_spine_export_panel_reachable_for_migrated_reports(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $manager = $this->makeRoleUser($shop->id, 'manager', ['reports.view', 'reports.export', 'reports.audit']);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($manager)
            ->get('/reports/operator-performance/export'))
            ->assertOk();
    }

    private function stubMeta(): \App\Services\Reporting\Dataset\ReportMeta
    {
        return new \App\Services\Reporting\Dataset\ReportMeta(
            reportKey: 'stub', reportVersion: 'stub@1', title: 'T', profileLabel: 'Detailed',
            format: 'csv', filtersApplied: [], periodLabel: 'P', shopLegalName: 'Shop',
            shopAddress: null, shopGstin: null, shopStateCode: null,
            generatedByName: 'Tester', generatedAt: CarbonImmutable::now(), generatorTag: 'test',
            watermark: null,
        );
    }
}

<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use App\Reporting\GstReportingService;
use App\Reporting\ReportPeriod;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\DailySummaryDataset;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 3 Accounting — Daily (Sales Summary). Lightweight: one day's headline
 * sales/GST (wrapping GstReportingService::summary) plus the day's metal movement
 * (wrapping LedgerService::metalMovementDay). Reconciles to the Sales Register /
 * finalized invoices / GST Report for the same day by construction.
 */
class DailySummaryReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const DATE = '2026-03-01';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function finalizedInvoice(int $shopId, int $customerId, int $userId, string $number, float $subtotal, float $gst): void
    {
        $total = $subtotal + $gst;
        $cgst = round($gst / 2, 2);
        $id = (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId, 'user_id' => $userId,
            'invoice_number' => $number, 'gold_rate' => 7200,
            'subtotal' => $subtotal, 'discount' => 0, 'gst' => $gst, 'gst_rate' => 3, 'total' => $total,
            'cgst_amount' => $cgst, 'sgst_amount' => round($gst - $cgst, 2), 'igst_amount' => 0,
            'status' => Invoice::STATUS_DRAFT, 'finalized_at' => null,
            'created_at' => self::DATE . ' 10:00:00', 'updated_at' => self::DATE . ' 10:00:00',
        ]);
        $item = $this->createItem($shopId);
        DB::table('invoice_items')->insert([
            'invoice_id' => $id, 'item_id' => $item->id, 'weight' => 10, 'rate' => $subtotal / 10,
            'making_charges' => 0, 'stone_amount' => 0, 'line_total' => $subtotal, 'gst_rate' => 3,
            'gst_amount' => $gst, 'metal_type' => 'gold', 'hsn_code' => '7113',
            'created_at' => self::DATE . ' 10:00:00', 'updated_at' => self::DATE . ' 10:00:00',
        ]);
        DB::table('invoices')->where('id', $id)->update(['status' => Invoice::STATUS_FINALIZED, 'finalized_at' => self::DATE . ' 10:00:00']);
    }

    private function metalMove(int $shopId, string $type, float $fine, string $at): void
    {
        DB::table('metal_movements')->insert([
            'shop_id' => $shopId, 'type' => $type, 'fine_weight' => $fine,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** Bills: 103000 + 51500 = 154500 sales · 3000 + 1500 = 4500 GST · 2 bills. */
    private function seedDay(int $shopId, int $userId): void
    {
        $c = $this->createCustomer($shopId)->id;
        $this->finalizedInvoice($shopId, $c, $userId, 'INV-D-1', 100000, 3000);
        $this->finalizedInvoice($shopId, $c, $userId, 'INV-D-2', 50000, 1500);
        $this->metalMove($shopId, 'sale', 18.5, self::DATE . ' 10:30:00');
    }

    private function filters(): array
    {
        return ['period' => ['from' => CarbonImmutable::parse(self::DATE), 'to' => CarbonImmutable::parse(self::DATE)]];
    }

    private function request(int $shopId, F $format): ReportRequest
    {
        $def = app(ReportRegistry::class)->definition(DailySummaryDataset::KEY);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(true);
        $keys = app(ColumnPolicy::class)->resolve($def, P::Detailed, $user)->columnKeys;

        return new ReportRequest(
            definition: $def, shopId: $shopId, userId: 1, userName: 'Manager', profile: P::Detailed,
            format: $format, filters: $this->filters(), columnKeys: $keys,
        );
    }

    private function meta(ReportRequest $r): ReportMeta
    {
        return new ReportMeta(
            reportKey: $r->definition->key, reportVersion: $r->definition->version, title: $r->definition->title,
            profileLabel: 'Detailed', format: $r->format->value, filtersApplied: ['Date' => self::DATE],
            periodLabel: 'As on 01 Mar 2026', shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null,
            shopStateCode: null, generatedByName: 'Manager', generatedAt: now(), generatorTag: 'test',
        );
    }

    private function build(int $shopId)
    {
        $r = $this->request($shopId, F::Csv);

        return TenantContext::runFor($shopId, fn () => app(ReportRegistry::class)->datasetService(DailySummaryDataset::KEY)->build($r, $this->meta($r)));
    }

    // ---- SALES / COUNT / GST RECONCILIATION -----------------------------

    public function test_sales_summary_reconciles_to_sales_gst_count(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedDay($shop->id, $owner->id);

        $ds = $this->build($shop->id);
        $row = $ds->section('sales')->rows[0];

        $this->assertEqualsWithDelta(154500.0, (float) $row['total_sales'], 0.01);
        $this->assertSame(2, (int) $row['bills']);
        $this->assertEqualsWithDelta(4500.0, (float) $row['gst'], 0.01);

        $day = ReportPeriod::day(self::DATE);

        // DAILY-1 — daily sales == Sales Register total for the day (raw salesIn).
        $rawSales = (float) Invoice::withoutTenant()->where('shop_id', $shop->id)->salesIn($day)->sum('total');
        $this->assertEqualsWithDelta($rawSales, (float) $row['total_sales'], 0.01, 'sales == Sales Register');

        // DAILY-2 — daily bills == finalized invoice count for the day.
        $rawCount = (int) Invoice::withoutTenant()->where('shop_id', $shop->id)->salesIn($day)->count();
        $this->assertSame($rawCount, (int) $row['bills'], 'bills == finalized count');

        // DAILY-3 — daily GST == GST Report total for the day (raw salesIn).
        $rawGst = (float) Invoice::withoutTenant()->where('shop_id', $shop->id)->salesIn($day)->sum('gst');
        $this->assertEqualsWithDelta($rawGst, (float) $row['gst'], 0.01, 'gst == GST Report');

        // DAILY-4 — split consistency (CGST+SGST+IGST == GST), all from the service.
        $summary = TenantContext::runFor($shop->id, fn () => app(GstReportingService::class)->summary($shop->id, $day));
        $this->assertEqualsWithDelta(
            $summary->gstCollected,
            $summary->cgstCollected + $summary->sgstCollected + $summary->igstCollected,
            0.02
        );

        // Metal movement section preserves the day's gold movement (friendly label).
        $metal = $ds->section('metal')->rows;
        $this->assertEqualsWithDelta(18.5, (float) $metal[0]['grams'], 0.0001);
        $this->assertSame('Sold', $metal[0]['movement']);
    }

    // ---- VALIDATOR (reports:validate DAILY-1/2/3/4) ---------------------

    public function test_reports_validate_daily_summary_path_passes(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedDay($shop->id, $owner->id);

        $this->artisan('reports:validate', ['--shop' => $shop->id, '--month' => 3, '--year' => 2026])
            ->expectsOutputToContain('DAILY-1 daily sales == Sales Register total (raw salesIn)')
            ->expectsOutputToContain('DAILY-2 daily bills == finalized invoice count (raw salesIn)')
            ->expectsOutputToContain('DAILY-3 daily GST == GST Report total (raw salesIn)')
            ->expectsOutputToContain('DAILY-4 summary reconciliation (CGST+SGST+IGST == GST)')
            ->assertExitCode(0);
    }

    // ---- EXPORTS / PARITY -----------------------------------------------

    public function test_all_file_formats_render(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedDay($shop->id, $owner->id);

        foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
            $request = $this->request($shop->id, $format);
            $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($request)));
            $this->assertGreaterThan(0, $result->output->byteSize(), $format->value);
        }
    }

    // ---- PERMISSIONS (reports.view gate) --------------------------------

    public function test_screen_requires_reports_view_permission(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedDay($shop->id, $owner->id);
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->detach(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/daily?date=' . self::DATE))->assertForbidden();

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/daily?date=' . self::DATE))
            ->assertOk()->assertSee('Daily Sales Summary');
    }

    // ---- TENANT ISOLATION + PERFORMANCE ---------------------------------

    public function test_tenant_isolation_and_query_bounded(): void
    {
        [$ownerA, $shopA] = $this->createManufacturerTenant();
        [$ownerB, $shopB] = $this->createManufacturerTenant();
        $this->seedDay($shopA->id, $ownerA->id);
        // B noise: a bill on the same day that must never reach A's summary.
        $cB = $this->createCustomer($shopB->id)->id;
        $this->finalizedInvoice($shopB->id, $cB, $ownerB->id, 'INV-B-1', 999999, 0);

        DB::enableQueryLog();
        $ds = $this->build($shopA->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEqualsWithDelta(154500.0, (float) $ds->section('sales')->rows[0]['total_sales'], 0.01, 'A must not see B');
        $this->assertLessThanOrEqual(12, $queries, "Expected bounded queries, got {$queries}");
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

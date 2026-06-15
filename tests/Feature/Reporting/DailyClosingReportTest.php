<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use App\Reporting\GstReportingService;
use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\DailyClosingDataset;
use App\Services\Reporting\Reports\SalesRegisterDataset;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 3 Accounting — Daily Closing. The cross-phase trust milestone: wraps the
 * canonical ClosingService (GstReportingService + LedgerService::cashFlow) and
 * must reconcile simultaneously to the Sales Register, the GST Report, and the
 * Cash Flow report for the same date.
 */
class DailyClosingReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const DATE = '2026-03-01';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function finalizedInvoice(int $shopId, int $customerId, int $userId): void
    {
        $id = (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId, 'user_id' => $userId,
            'invoice_number' => 'INV-CLOSE-1', 'gold_rate' => 7200,
            'subtotal' => 100000, 'discount' => 0, 'gst' => 3000, 'gst_rate' => 3, 'total' => 103000,
            'cgst_amount' => 1500, 'sgst_amount' => 1500, 'igst_amount' => 0,
            'status' => Invoice::STATUS_DRAFT, 'finalized_at' => null,
            'created_at' => self::DATE . ' 10:00:00', 'updated_at' => self::DATE . ' 10:00:00',
        ]);
        $item = $this->createItem($shopId);
        DB::table('invoice_items')->insert([
            'invoice_id' => $id, 'item_id' => $item->id, 'weight' => 10, 'rate' => 5000,
            'making_charges' => 0, 'stone_amount' => 0, 'line_total' => 100000, 'gst_rate' => 3,
            'gst_amount' => 3000, 'metal_type' => 'gold', 'hsn_code' => '7113',
            'created_at' => self::DATE . ' 10:00:00', 'updated_at' => self::DATE . ' 10:00:00',
        ]);
        DB::table('invoices')->where('id', $id)->update(['status' => Invoice::STATUS_FINALIZED, 'finalized_at' => self::DATE . ' 10:00:00']);
    }

    private function cash(int $shopId, int $userId, string $type, float $amount, string $at): void
    {
        DB::table('cash_transactions')->insert([
            'shop_id' => $shopId, 'user_id' => $userId, 'type' => $type, 'amount' => $amount,
            'payment_mode' => 'cash', 'source_type' => 'manual', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function seedDay(int $shopId, int $userId): void
    {
        $customerId = $this->createCustomer($shopId)->id;
        $this->finalizedInvoice($shopId, $customerId, $userId);     // sales 103000, gst 3000
        $this->cash($shopId, $userId, 'in', 5000, '2026-02-15 10:00:00');   // opening
        $this->cash($shopId, $userId, 'in', 8000, self::DATE . ' 11:00:00');
        $this->cash($shopId, $userId, 'out', 2000, self::DATE . ' 12:00:00'); // closing 11000
    }

    private function dayFilters(): array
    {
        return ['period' => ['from' => CarbonImmutable::parse(self::DATE), 'to' => CarbonImmutable::parse(self::DATE)]];
    }

    private function request(string $key, int $shopId, F $format, array $filters): ReportRequest
    {
        $def = app(ReportRegistry::class)->definition($key);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(true);
        $keys = app(ColumnPolicy::class)->resolve($def, P::Detailed, $user)->columnKeys;

        return new ReportRequest(
            definition: $def, shopId: $shopId, userId: 1, userName: 'Manager', profile: P::Detailed,
            format: $format, filters: $filters, columnKeys: $keys,
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

    private function build(string $key, int $shopId, array $filters)
    {
        $r = $this->request($key, $shopId, F::Csv, $filters);

        return TenantContext::runFor($shopId, fn () => app(ReportRegistry::class)->datasetService($key)->build($r, $this->meta($r)));
    }

    private function metric(array $rows, string $label): float
    {
        foreach ($rows as $row) {
            if ($row['metric'] === $label) {
                return (float) $row['amount'];
            }
        }
        return PHP_FLOAT_MAX;
    }

    // ---- CROSS-PHASE RECONCILIATION (Sales + GST + Cash) ----------------

    public function test_daily_closing_reconciles_to_sales_gst_and_cash(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedDay($shop->id, $owner->id);

        $closing = $this->build(DailyClosingDataset::KEY, $shop->id, $this->dayFilters());
        $salesTax = $closing->section('sales_tax')->rows;
        $cash = $closing->section('cash')->rows;

        // Closing figures.
        $totalSales = $this->metric($salesTax, 'Total Sales');
        $gst = $this->metric($salesTax, 'GST Collected');
        $cashClosing = $this->metric($cash, 'Closing Balance');

        $this->assertEqualsWithDelta(103000.0, $totalSales, 0.01);
        $this->assertEqualsWithDelta(3000.0, $gst, 0.01);
        $this->assertEqualsWithDelta(11000.0, $cashClosing, 0.01); // 5000 + 8000 − 2000

        $day = ReportPeriod::day(self::DATE);

        // CLOSE-1 — == Sales Register total for the date.
        $register = $this->build(SalesRegisterDataset::KEY, $shop->id, $this->dayFilters());
        $registerTotal = (float) $register->section('sales')->totals['total'];
        $this->assertEqualsWithDelta($registerTotal, $totalSales, 0.01, 'closing == Sales Register');

        // CLOSE-2 — == GST Report totals for the date.
        $gstReport = TenantContext::runFor($shop->id, fn () => app(GstReportingService::class)->summary($shop->id, $day));
        $this->assertEqualsWithDelta($gstReport->totalSales, $totalSales, 0.01, 'closing == GST report sales');
        $this->assertEqualsWithDelta($gstReport->gstCollected, $gst, 0.01, 'closing == GST report GST');

        // CLOSE-3 — == Cash Flow totals for the date.
        $cashFlow = TenantContext::runFor($shop->id, fn () => app(LedgerService::class)->cashFlow($shop->id, $day));
        $this->assertEqualsWithDelta($cashFlow->closing, $cashClosing, 0.01, 'closing == Cash Flow closing');

        // CLOSE-4 — combined consistency: GST split sums to GST collected.
        $cgst = $this->metric($salesTax, 'CGST');
        $sgst = $this->metric($salesTax, 'SGST');
        $igst = $this->metric($salesTax, 'IGST');
        $this->assertEqualsWithDelta($gst, $cgst + $sgst + $igst, 0.02);
    }

    // ---- VALIDATOR (reports:validate CLOSE-1/2/3/4) ---------------------

    public function test_reports_validate_closing_path_passes(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedDay($shop->id, $owner->id);

        $this->artisan('reports:validate', ['--shop' => $shop->id, '--month' => 3, '--year' => 2026])
            ->expectsOutputToContain('CLOSE-1 closing sales == Sales Register total (raw salesIn)')
            ->expectsOutputToContain('CLOSE-2 closing GST == GST Report total (raw salesIn)')
            ->expectsOutputToContain('CLOSE-3 closing cash == Cash Flow closing (raw cash_transactions)')
            ->expectsOutputToContain('CLOSE-4 combined closing reconciliation (cash equation + consistency)')
            ->assertExitCode(0);
    }

    // ---- EXPORTS / PARITY -----------------------------------------------

    public function test_all_file_formats_render(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedDay($shop->id, $owner->id);

        foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
            $request = $this->request(DailyClosingDataset::KEY, $shop->id, $format, $this->dayFilters());
            $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($request)));
            $this->assertGreaterThan(0, $result->output->byteSize(), $format->value);
        }
    }

    // ---- PERMISSIONS (reports.daily_closing) ----------------------------

    public function test_screen_requires_daily_closing_permission(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedDay($shop->id, $owner->id);
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        // The shared tenant factory now provisions the owner with the full
        // permission set (like a real owner). To prove the daily-closing gate
        // denies without its permission, revoke just reports.daily_closing first.
        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->revokePermission('reports.daily_closing');
        });
        $owner->unsetRelation('role');

        // Without the permission → 403.
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/closing?date=' . self::DATE))->assertForbidden();

        // With it → 200 + spine screen.
        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(Permission::whereIn('name', ['reports.daily_closing'])->pluck('id'));
        });
        $owner->unsetRelation('role');
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/closing?date=' . self::DATE))
            ->assertOk()->assertSee('Daily Closing')->assertDontSee('window.print');
    }

    // ---- TENANT ISOLATION + PERFORMANCE ---------------------------------

    public function test_tenant_isolation_and_query_bounded(): void
    {
        [$ownerA, $shopA] = $this->createManufacturerTenant();
        [$ownerB, $shopB] = $this->createManufacturerTenant();
        $this->seedDay($shopA->id, $ownerA->id);
        $this->cash($shopB->id, $ownerB->id, 'in', 999999, self::DATE . ' 11:00:00'); // B noise

        DB::enableQueryLog();
        $closing = $this->build(DailyClosingDataset::KEY, $shopA->id, $this->dayFilters());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEqualsWithDelta(11000.0, $this->metric($closing->section('cash')->rows, 'Closing Balance'), 0.01, 'A must not see B cash');
        $this->assertLessThanOrEqual(10, $queries, "Expected bounded queries, got {$queries}");
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

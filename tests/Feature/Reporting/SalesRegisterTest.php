<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Models\User;
use App\Reporting\GstReportingService;
use App\Reporting\ReportPeriod;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\SalesRegisterDataset;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 1 pilot — Sales / Invoice Register, proven on all the dimensions that
 * gate Phase 2: reconciliation, parity, exports, profiles, permissions,
 * performance.
 */
class SalesRegisterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ---- seeding ---------------------------------------------------------

    private function insertInvoice(int $shopId, ?int $customerId, array $attrs): int
    {
        return (int) DB::table('invoices')->insertGetId(array_merge([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => 0, 'discount' => 0, 'gst' => 0, 'gst_rate' => 3,
            'total' => 0, 'status' => Invoice::STATUS_FINALIZED,
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00',
            'finalized_at' => '2026-03-15 10:00:00',
        ], $attrs));
    }

    private function addItem(int $invoiceId, int $shopId, float $cost, float $lineTotal, float $gstAmount): void
    {
        $item = $this->createItem($shopId);
        DB::table('items')->where('id', $item->id)->update(['cost_price' => $cost]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId, 'item_id' => $item->id, 'weight' => 10, 'rate' => 5000,
            'making_charges' => 0, 'stone_amount' => 0, 'line_total' => $lineTotal, 'gst_rate' => 3,
            'gst_amount' => $gstAmount, 'metal_type' => 'gold', 'hsn_code' => '7113',
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00',
        ]);
    }

    /**
     * Items can only be attached while the invoice is a draft (the finalized
     * guard blocks mutation once finalized), so seed draft → items → finalize.
     * Item line_total/gst_amount must sum to the invoice subtotal/gst (finalize
     * trigger), so they are distributed across the given item costs.
     */
    private function finalizedInvoice(int $shopId, int $customerId, array $financials, array $costs, string $finalizedAt = '2026-03-15 10:00:00'): int
    {
        $id = $this->insertInvoice($shopId, $customerId, array_merge($financials, ['status' => Invoice::STATUS_DRAFT, 'finalized_at' => null]));

        $n = count($costs);
        $subtotal = (float) $financials['subtotal'];
        $gst = (float) ($financials['gst'] ?? 0);
        $lineEach = round($subtotal / $n, 2);
        $gstEach = round($gst / $n, 2);

        foreach (array_values($costs) as $i => $cost) {
            $last = $i === $n - 1;
            $this->addItem(
                $id, $shopId, (float) $cost,
                $last ? round($subtotal - $lineEach * ($n - 1), 2) : $lineEach,
                $last ? round($gst - $gstEach * ($n - 1), 2) : $gstEach,
            );
        }

        DB::table('invoices')->where('id', $id)->update(['status' => Invoice::STATUS_FINALIZED, 'finalized_at' => $finalizedAt, 'updated_at' => $finalizedAt]);

        return $id;
    }

    /** Two in-period finalized sales (150000 taxable / 4500 gst) + noise excluded. */
    private function seedCanonical(int $shopId): void
    {
        $customerId = $this->createCustomer($shopId)->id;

        $this->finalizedInvoice($shopId, $customerId, ['subtotal' => 100000, 'gst' => 3000, 'cgst_amount' => 1500, 'sgst_amount' => 1500, 'igst_amount' => 0, 'total' => 103000], [60000]);
        $this->finalizedInvoice($shopId, $customerId, ['subtotal' => 50000, 'gst' => 1500, 'cgst_amount' => 750, 'sgst_amount' => 750, 'igst_amount' => 0, 'total' => 51500], [30000]);

        // Excluded: draft, and a finalized invoice dated outside March.
        $this->insertInvoice($shopId, $customerId, ['status' => Invoice::STATUS_DRAFT, 'subtotal' => 999999, 'gst' => 99999, 'total' => 1099998, 'finalized_at' => null]);
        $this->finalizedInvoice($shopId, $customerId, ['subtotal' => 888888, 'gst' => 88888, 'total' => 977776], [0], '2026-02-10 10:00:00');
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::month(2026, 3);
    }

    // ---- request building ------------------------------------------------

    private function definition(): ReportDefinition
    {
        return app(ReportRegistry::class)->definition(SalesRegisterDataset::KEY);
    }

    private function keysFor(P $profile, bool $sensitive): array
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn($sensitive);

        return app(ColumnPolicy::class)
            ->resolve($this->definition(), $profile, $user, includeSensitive: $sensitive)
            ->columnKeys;
    }

    private function request(int $shopId, P $profile, F $format, bool $sensitive): ReportRequest
    {
        $period = $this->period();

        return new ReportRequest(
            definition: $this->definition(),
            shopId: $shopId,
            userId: 1,
            userName: 'Tester',
            profile: $profile,
            format: $format,
            filters: ['period' => ['from' => $period->start(), 'to' => $period->end()]],
            columnKeys: $this->keysFor($profile, $sensitive),
            includeSensitive: $sensitive,
        );
    }

    private function meta(ReportRequest $request): ReportMeta
    {
        $period = $this->period();

        return new ReportMeta(
            reportKey: SalesRegisterDataset::KEY, reportVersion: SalesRegisterDataset::VERSION,
            title: 'Sales / Invoice Register', profileLabel: 'Detailed', format: $request->format->value,
            filtersApplied: ['Period' => 'March 2026'], periodLabel: 'March 2026',
            shopLegalName: 'Goldlux', shopAddress: null, shopGstin: '29ABCDE1234F1Z5', shopStateCode: '29',
            generatedByName: 'Tester', generatedAt: now(), generatorTag: 'test', watermark: null,
        );
    }

    private function build(int $shopId, ReportRequest $request)
    {
        return TenantContext::runFor($shopId, fn () => app(SalesRegisterDataset::class)->build($request, $this->meta($request)));
    }

    // ---- 1. RECONCILIATION ----------------------------------------------

    public function test_grand_totals_reconcile_to_gst_report_and_raw_invoices(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedCanonical($shop->id);

        $dataset = $this->build($shop->id, $this->request($shop->id, P::Summary, F::Csv, false));
        $totals = $dataset->section('sales')->totals;

        // Register grand totals.
        $this->assertSame(2, $dataset->totalRowCount());
        $this->assertEqualsWithDelta(150000.0, $totals['taxable'], 0.01);
        $this->assertEqualsWithDelta(2250.0, $totals['cgst'], 0.01);
        $this->assertEqualsWithDelta(2250.0, $totals['sgst'], 0.01);
        $this->assertEqualsWithDelta(0.0, $totals['igst'], 0.01);
        $this->assertEqualsWithDelta(4500.0, $totals['total_gst'], 0.01);
        $this->assertEqualsWithDelta(154500.0, $totals['total'], 0.01);

        // ↔ GST report (same period, same source of truth).
        $gst = TenantContext::runFor($shop->id, fn () => app(GstReportingService::class)->summary($shop->id, $this->period()));
        $this->assertEqualsWithDelta($gst->taxableAmount, $totals['taxable'], 0.01);
        $this->assertEqualsWithDelta($gst->gstCollected, $totals['total_gst'], 0.01);
        $this->assertEqualsWithDelta($gst->cgstCollected, $totals['cgst'], 0.01);
        $this->assertEqualsWithDelta($gst->sgstCollected, $totals['sgst'], 0.01);

        // ↔ raw finalized invoices table.
        $rawTaxable = TenantContext::runFor($shop->id, fn () => (float) Invoice::salesIn($this->period())->sum('subtotal'));
        $this->assertEqualsWithDelta($rawTaxable, $totals['taxable'], 0.01);
    }

    // ---- 2 & 5. PARITY ACROSS PROFILES + FORMATS (exports) --------------

    public function test_every_profile_renders_in_every_file_format(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedCanonical($shop->id);

        foreach ([P::Summary, P::Detailed, P::Ca, P::CaStandard, P::Raw] as $profile) {
            foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
                $request = $this->request($shop->id, $profile, $format, sensitive: true);
                $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($request)));

                $this->assertSame(2, $result->rowCount, "{$profile->value}/{$format->value} row count");
                $this->assertGreaterThan(0, $result->output->byteSize(), "{$profile->value}/{$format->value} produced bytes");
            }
        }
    }

    public function test_csv_output_carries_raw_reconciling_values(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedCanonical($shop->id);

        $request = $this->request($shop->id, P::Detailed, F::Csv, sensitive: false);
        $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($request)));

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($result->output->contents))));
        $header = array_shift($rows);
        $taxableIdx = array_search('Taxable Value', $header, true);
        $this->assertNotFalse($taxableIdx, 'CSV header has the Taxable Value column');

        $sum = 0.0;
        foreach ($rows as $row) {
            if (($row[0] ?? '') !== '' && str_starts_with((string) $row[0], 'INV-')) {
                $sum += (float) $row[$taxableIdx]; // raw value, no ₹/grouping
            }
        }
        $this->assertEqualsWithDelta(150000.0, $sum, 0.01);
    }

    // ---- 3. PERMISSIONS (sensitive gate) --------------------------------

    public function test_sensitive_columns_gated_by_permission(): void
    {
        $without = $this->keysFor(P::Detailed, sensitive: false);
        $with = $this->keysFor(P::Detailed, sensitive: true);

        foreach (['cost', 'margin', 'operator', 'customer_mobile', 'customer_address'] as $sensitiveCol) {
            $this->assertNotContains($sensitiveCol, $without, "{$sensitiveCol} hidden without permission");
            $this->assertContains($sensitiveCol, $with, "{$sensitiveCol} shown with permission");
        }
    }

    // ---- 4. PROFILES -----------------------------------------------------

    public function test_summary_is_mandatory_only_detailed_adds_optional(): void
    {
        $summary = $this->keysFor(P::Summary, sensitive: false);
        $detailed = $this->keysFor(P::Detailed, sensitive: false);

        $this->assertSame(['invoice_no', 'date', 'customer', 'taxable', 'cgst', 'sgst', 'igst', 'total_gst', 'total', 'status'], $summary);
        $this->assertContains('hsn', $detailed);
        $this->assertContains('payment_mode', $detailed);
    }

    public function test_ca_standard_is_locked_and_excludes_sensitive(): void
    {
        $caStandard = $this->keysFor(P::CaStandard, sensitive: true); // opt-in + permission

        foreach (['cost', 'margin', 'operator', 'customer_mobile'] as $sensitiveCol) {
            $this->assertNotContains($sensitiveCol, $caStandard, "CA Standard never includes {$sensitiveCol}");
        }
        $this->assertContains('hsn', $caStandard, 'CA Standard keeps the canonical optional set');
    }

    // ---- 6. PERFORMANCE (no N+1) ----------------------------------------

    public function test_build_is_query_bounded_regardless_of_volume(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $customerId = $this->createCustomer($shop->id)->id;
        for ($i = 0; $i < 12; $i++) {
            $this->finalizedInvoice($shop->id, $customerId, ['subtotal' => 1000, 'gst' => 30, 'total' => 1030], [600, 200]);
        }

        $request = $this->request($shop->id, P::Detailed, F::Csv, sensitive: true); // exercises eager item loads

        DB::enableQueryLog();
        $dataset = $this->build($shop->id, $request);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(12, $dataset->totalRowCount());
        $this->assertLessThanOrEqual(8, $queries, "Expected O(1) queries via eager loading, got {$queries}");
    }

    // ---- screen (end-to-end render) -------------------------------------

    public function test_screen_renders_with_reconciling_totals(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedCanonical($shop->id);

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $ids = \App\Models\Permission::whereIn('name', ['reports.view'])->pluck('id');
            $owner->role->permissions()->syncWithoutDetaching($ids);
        });

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)
            ->get('/report/sales-register?date_preset=custom&date_from=2026-03-01&date_to=2026-03-31'));

        $response->assertOk();
        $response->assertSee('Sales / Invoice Register');
        $response->assertSee('1,50,000'); // taxable grand total, Indian grouping
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

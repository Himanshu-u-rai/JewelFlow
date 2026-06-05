<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Models\User;
use App\Reporting\GstReportingService;
use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
use App\Reporting\TaxService;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\CreditNoteRegisterDataset;
use App\Services\Reporting\Reports\DayBookDataset;
use App\Services\Reporting\Reports\GstReportDataset;
use App\Services\Reporting\Reports\Gstr1Dataset;
use App\Services\Reporting\Reports\Gstr3bDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 2 — the compliance family (GST / GSTR-1 / GSTR-3B / CN Register) plus
 * the Day Book. Each dataset wraps an existing canonical service, so the proof
 * here is reconciliation-by-construction: every section's totals equal the
 * wrapped service's own DTO totals (and, across reports, equal each other).
 *
 * Fixed period (March 2026):
 *   - INV-B2B   finalized  taxable 100000, gst 3000 (cgst/sgst 1500/1500)  buyer GSTIN + PoS 29
 *   - INV-B2CS  finalized  taxable  50000, gst 1500 (cgst/sgst  750/ 750)  walk-in (no GSTIN)
 *   - CN-1      credit note (return) gst 600 against INV-B2B
 * Expected output tax: taxable 150000, gst 4500, cgst/sgst 2250/2250; CN gst 600.
 */
class ComplianceReportsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const YEAR = 2026;
    private const MONTH = 3;

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

    private function addItem(int $invoiceId, int $shopId, float $lineTotal, float $gstAmount): void
    {
        $item = $this->createItem($shopId);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId, 'item_id' => $item->id, 'weight' => 10, 'rate' => 5000,
            'making_charges' => 0, 'stone_amount' => 0, 'line_total' => $lineTotal, 'gst_rate' => 3,
            'gst_amount' => $gstAmount, 'metal_type' => 'gold', 'hsn_code' => '7113',
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00',
        ]);
    }

    /** Seed draft → item (sums to subtotal/gst) → finalize (the finalize guard blocks post-finalize mutation). */
    private function finalizedInvoice(int $shopId, int $customerId, array $financials): int
    {
        $id = $this->insertInvoice($shopId, $customerId, array_merge($financials, ['status' => Invoice::STATUS_DRAFT, 'finalized_at' => null]));
        $this->addItem($id, $shopId, (float) $financials['subtotal'], (float) ($financials['gst'] ?? 0));
        DB::table('invoices')->where('id', $id)->update(['status' => Invoice::STATUS_FINALIZED, 'finalized_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00']);

        return $id;
    }

    private function insertCreditNote(int $shopId, int $invoiceId, int $customerId, int $userId, array $attrs): void
    {
        $returnOrderId = (int) DB::table('return_orders')->insertGetId([
            'shop_id' => $shopId, 'invoice_id' => $invoiceId, 'return_type' => 'customer_return',
            'status' => 'settled', 'created_by_user_id' => $userId,
            'created_at' => '2026-03-20 10:00:00', 'updated_at' => '2026-03-20 10:00:00',
        ]);

        DB::table('credit_notes')->insert(array_merge([
            'shop_id' => $shopId, 'return_order_id' => $returnOrderId, 'invoice_id' => $invoiceId,
            'customer_id' => $customerId, 'credit_note_sequence' => 1,
            'credit_note_number' => 'CN-' . fake()->unique()->numerify('#####'),
            'subtotal' => 0, 'gst' => 0, 'gst_rate' => 3, 'total' => 0, 'status' => 'issued',
            'issued_at' => '2026-03-20 10:00:00', 'issued_by_user_id' => $userId,
            'created_at' => '2026-03-20 10:00:00', 'updated_at' => '2026-03-20 10:00:00',
        ], $attrs));
    }

    /** @return array{0: User, 1: \App\Models\Shop} */
    private function seedFixture(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        // B2B: registered buyer (GSTIN + place of supply).
        $invB2b = $this->finalizedInvoice($shop->id, $customer->id, [
            'subtotal' => 100000, 'gst' => 3000, 'cgst_amount' => 1500, 'sgst_amount' => 1500, 'igst_amount' => 0, 'total' => 103000,
            'buyer_gstin' => '29AABCU9603R1ZX', 'place_of_supply_state_code' => '29',
        ]);

        // B2CS: walk-in (no GSTIN).
        $this->finalizedInvoice($shop->id, $customer->id, [
            'subtotal' => 50000, 'gst' => 1500, 'cgst_amount' => 750, 'sgst_amount' => 750, 'igst_amount' => 0, 'total' => 51500,
            'place_of_supply_state_code' => '29',
        ]);

        // Credit note (return) in March against the B2B invoice.
        $this->insertCreditNote($shop->id, $invB2b, $customer->id, $user->id, [
            'subtotal' => 20000, 'gst' => 600, 'cgst_amount' => 300, 'sgst_amount' => 300, 'igst_amount' => 0, 'total' => 20600,
        ]);

        return [$user, $shop];
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::month(self::YEAR, self::MONTH);
    }

    // ---- request / meta building ----------------------------------------

    private function definition(string $key): ReportDefinition
    {
        return app(ReportRegistry::class)->definition($key);
    }

    private function service(string $key): ReportDatasetService
    {
        return app(ReportRegistry::class)->datasetService($key);
    }

    private function allKeys(ReportDefinition $definition): array
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(false); // compliance ignores this; included for the gate path

        return app(ColumnPolicy::class)
            ->resolve($definition, $definition->profiles[0], $user, includeSensitive: false)
            ->columnKeys;
    }

    private function request(int $shopId, string $key, P $profile, F $format): ReportRequest
    {
        $definition = $this->definition($key);
        $period = $this->period();

        return new ReportRequest(
            definition: $definition,
            shopId: $shopId,
            userId: 1,
            userName: 'Tester',
            profile: $profile,
            format: $format,
            filters: ['period' => ['from' => $period->start(), 'to' => $period->end()]],
            columnKeys: $this->allKeys($definition),
            includeSensitive: false,
        );
    }

    private function meta(string $key, ReportRequest $request): ReportMeta
    {
        $definition = $this->definition($key);

        return new ReportMeta(
            reportKey: $key, reportVersion: $definition->version,
            title: $definition->title, profileLabel: 'Fixed', format: $request->format->value,
            filtersApplied: ['Period' => 'March 2026'], periodLabel: 'March 2026',
            shopLegalName: 'Goldlux', shopAddress: null, shopGstin: '29ABCDE1234F1Z5', shopStateCode: '29',
            generatedByName: 'Tester', generatedAt: now(), generatorTag: 'test', watermark: null,
        );
    }

    private function build(int $shopId, string $key, P $profile = P::Fixed)
    {
        $request = $this->request($shopId, $key, $profile, F::Csv);

        return TenantContext::runFor($shopId, fn () => $this->service($key)->build($request, $this->meta($key, $request)));
    }

    // ---- 1. RECONCILIATION (the core) -----------------------------------

    public function test_gst_report_totals_reconcile_to_the_gst_service(): void
    {
        [, $shop] = $this->seedFixture();

        $summary = TenantContext::runFor($shop->id, fn () => app(GstReportingService::class)->summary($shop->id, $this->period()));
        $totals = $this->build($shop->id, GstReportDataset::KEY)->section('gst')->totals;

        $this->assertEqualsWithDelta($summary->taxableAmount, $totals['taxable'], 0.01);   // == 150000
        $this->assertEqualsWithDelta($summary->cgstCollected, $totals['cgst'], 0.01);       // == 2250
        $this->assertEqualsWithDelta($summary->sgstCollected, $totals['sgst'], 0.01);       // == 2250
        $this->assertEqualsWithDelta($summary->igstCollected, $totals['igst'], 0.01);       // == 0
        $this->assertEqualsWithDelta($summary->gstCollected, $totals['total_gst'], 0.01);   // == 4500
        $this->assertEqualsWithDelta($summary->totalSales, $totals['total'], 0.01);         // == 154500
        $this->assertSame($summary->invoiceCount, $totals['count']);                        // == 2
        $this->assertEqualsWithDelta(150000.0, $totals['taxable'], 0.01);
        $this->assertEqualsWithDelta(4500.0, $totals['total_gst'], 0.01);
    }

    public function test_gstr1_section_totals_reconcile_to_the_tax_service(): void
    {
        [, $shop] = $this->seedFixture();

        $gstr1 = TenantContext::runFor($shop->id, fn () => app(TaxService::class)->gstr1($shop->id, $this->period()));
        $dataset = $this->build($shop->id, Gstr1Dataset::KEY);

        $b2b = $dataset->section('b2b')->totals;
        $b2cs = $dataset->section('b2cs')->totals;

        // B2B + B2CS taxable/cgst/sgst/igst sums == the GSTR-1 grand scalars.
        $this->assertEqualsWithDelta($gstr1->taxable, $b2b['taxable'] + $b2cs['taxable'], 0.01); // == 150000
        $this->assertEqualsWithDelta($gstr1->cgst, $b2b['cgst'] + $b2cs['cgst'], 0.01);          // == 2250
        $this->assertEqualsWithDelta($gstr1->sgst, $b2b['sgst'] + $b2cs['sgst'], 0.01);          // == 2250
        $this->assertEqualsWithDelta($gstr1->igst, $b2b['igst'] + $b2cs['igst'], 0.01);          // == 0
        $this->assertEqualsWithDelta($gstr1->totalGst, $b2b['total'] - $b2b['taxable'] + $b2cs['total_gst'], 0.01); // 4500

        // Credit-note section == the DTO CN scalars.
        $cn = $dataset->section('credit_notes')->totals;
        $this->assertEqualsWithDelta($gstr1->cnTaxable, $cn['taxable'], 0.01); // == 20000
        $this->assertEqualsWithDelta($gstr1->cnGst, $cn['total_gst'], 0.01);   // == 600
    }

    public function test_gstr3b_rows_reconcile_to_the_tax_service(): void
    {
        [, $shop] = $this->seedFixture();

        $gstr3b = TenantContext::runFor($shop->id, fn () => app(TaxService::class)->gstr3b($shop->id, $this->period()));
        $rows = $this->build($shop->id, Gstr3bDataset::KEY)->section('gstr3b')->rows;

        // Row 0 = outward, row 1 = less CN, row 2 = net.
        $this->assertEqualsWithDelta($gstr3b->outwardTaxable, $rows[0]['taxable'], 0.01); // == 150000
        $this->assertEqualsWithDelta($gstr3b->outwardGst, $rows[0]['total_gst'], 0.01);   // == 4500
        $this->assertEqualsWithDelta(-$gstr3b->cnTaxable, $rows[1]['taxable'], 0.01);      // == -20000
        $this->assertEqualsWithDelta(-$gstr3b->cnGst, $rows[1]['total_gst'], 0.01);        // == -600
        $this->assertEqualsWithDelta($gstr3b->netTaxable, $rows[2]['taxable'], 0.01);      // == 130000
        $this->assertEqualsWithDelta($gstr3b->netGst, $rows[2]['total_gst'], 0.01);        // == 3900
    }

    public function test_cn_register_totals_reconcile_to_service_and_raw_table(): void
    {
        [, $shop] = $this->seedFixture();

        $register = TenantContext::runFor($shop->id, fn () => app(TaxService::class)->creditNoteRegister($shop->id, $this->period()));
        $totals = $this->build($shop->id, CreditNoteRegisterDataset::KEY)->section('cn_register')->totals;

        $this->assertEqualsWithDelta($register->totalTaxable, $totals['taxable'], 0.01);  // == 20000
        $this->assertEqualsWithDelta($register->totalGst, $totals['total_gst'], 0.01);    // == 600
        $this->assertEqualsWithDelta($register->totalValue, $totals['total'], 0.01);      // == 20600

        // ↔ raw credit_notes table for the period.
        $rawGst = TenantContext::runFor($shop->id, fn () => (float) DB::table('credit_notes')
            ->where('shop_id', $shop->id)
            ->whereBetween('issued_at', $this->period()->bounds())
            ->sum('gst'));
        $this->assertEqualsWithDelta($rawGst, $totals['total_gst'], 0.01); // == 600
    }

    public function test_day_book_totals_reconcile_to_the_ledger_service(): void
    {
        [, $shop] = $this->seedFixture();

        $book = TenantContext::runFor($shop->id, fn () => app(LedgerService::class)->dayBook($shop->id, $this->period()));
        $dataset = $this->build($shop->id, DayBookDataset::KEY, P::Detailed);
        $section = $dataset->section('day_book');

        // Sales = sum of the credit column on Sale-type rows == service salesTotal.
        $saleCredits = 0.0;
        foreach ($section->rows as $row) {
            if ($row['type'] === 'Sale') {
                $saleCredits += (float) $row['credit'];
            }
        }
        $this->assertEqualsWithDelta($book->salesTotal, $saleCredits, 0.01); // == 154500 (103000 + 51500)

        // Running balance closes at Σcredit − Σdebit.
        $this->assertEqualsWithDelta(
            $section->totals['credit'] - $section->totals['debit'],
            $section->totals['running_balance'],
            0.01,
        );
        $this->assertSame($book->eventCount, $section->rowCount());
    }

    public function test_cross_report_reconciliation_of_output_gst(): void
    {
        [, $shop] = $this->seedFixture();

        $gst = $this->build($shop->id, GstReportDataset::KEY)->section('gst')->totals['total_gst'];

        $gstr1 = TenantContext::runFor($shop->id, fn () => app(TaxService::class)->gstr1($shop->id, $this->period()))->totalGst;
        $gstr3bOutward = TenantContext::runFor($shop->id, fn () => app(TaxService::class)->gstr3b($shop->id, $this->period()))->outwardGst;

        // The single output-GST figure agrees across all three compliance reports.
        $this->assertEqualsWithDelta($gst, $gstr1, 0.01);
        $this->assertEqualsWithDelta($gst, $gstr3bOutward, 0.01);
        $this->assertEqualsWithDelta(4500.0, $gst, 0.01);
    }

    // ---- 2. RIGIDITY -----------------------------------------------------

    public function test_compliance_reports_are_rigid(): void
    {
        foreach ([GstReportDataset::KEY, Gstr1Dataset::KEY, Gstr3bDataset::KEY, CreditNoteRegisterDataset::KEY] as $key) {
            $definition = $this->definition($key);

            $this->assertSame([P::Fixed], $definition->profiles, "{$key} exposes only the Fixed profile");
            $this->assertFalse($definition->hasSensitiveColumns(), "{$key} declares no sensitive columns");
            foreach ($definition->columns as $column) {
                $this->assertTrue($column->isMandatory(), "{$key}.{$column->key} is mandatory");
            }
        }
    }

    public function test_day_book_is_accounting_not_compliance(): void
    {
        $definition = $this->definition(DayBookDataset::KEY);

        $this->assertSame(\App\Services\Reporting\Definition\ReportClassification::Accounting, $definition->classification);
        $this->assertContains(P::Detailed, $definition->profiles);
        $this->assertTrue($definition->supportsFormat(F::Pdf));
        $this->assertFalse($definition->hasSensitiveColumns());
    }

    // ---- 3. EXPORTS / PARITY --------------------------------------------

    public function test_every_compliance_report_renders_in_every_file_format(): void
    {
        [, $shop] = $this->seedFixture();

        $reports = [
            GstReportDataset::KEY => P::Fixed,
            Gstr1Dataset::KEY => P::Fixed,
            Gstr3bDataset::KEY => P::Fixed,
            CreditNoteRegisterDataset::KEY => P::Fixed,
            DayBookDataset::KEY => P::Detailed,
        ];

        foreach ($reports as $key => $profile) {
            foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
                $request = $this->request($shop->id, $key, $profile, $format);
                $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($key, $request)));

                $this->assertGreaterThan(0, $result->output->byteSize(), "{$key}/{$format->value} produced bytes");
            }
        }
    }

    // ---- 4. PERMISSIONS (rigid — gate never strips mandatory) -----------

    public function test_compliance_columns_survive_a_user_without_permission(): void
    {
        foreach ([GstReportDataset::KEY, Gstr1Dataset::KEY, Gstr3bDataset::KEY, CreditNoteRegisterDataset::KEY] as $key) {
            $definition = $this->definition($key);

            $user = Mockery::mock(User::class);
            $user->shouldReceive('hasPermission')->andReturn(false);

            $keys = app(ColumnPolicy::class)
                ->resolve($definition, P::Fixed, $user, includeSensitive: false)
                ->columnKeys;

            $expected = array_map(static fn (ColumnDefinition $c) => $c->key, $definition->columns);
            $this->assertSame($expected, $keys, "{$key} keeps every mandatory column despite no permission");
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

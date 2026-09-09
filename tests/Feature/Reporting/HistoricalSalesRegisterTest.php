<?php

namespace Tests\Feature\Reporting;

use App\Models\Customer;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\User;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Reports\HistoricalSalesRegisterDataset;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 4 — the Historical Sales Register (`HISTORICAL-BATCH-4-REQUIREMENTS.md`
 * §2/§3/§5 tests 8-11, §9.7.1). This is the "zero open product decisions"
 * slice: a flat read-only list of `historical_sales_documents`, no aging, no
 * dedup, no Combined mode.
 */
class HistoricalSalesRegisterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    /** Every table a LIVE sale can touch — the register must never write to any of them. */
    private const MONEY_TABLES = [
        'customer_opening_balances',
        'invoices',
        'invoice_items',
        'invoice_payments',
        'cash_transactions',
        'customer_gold_transactions',
        'loyalty_transactions',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ---- seeding ----------------------------------------------------------

    private function makeBatch(int $shopId): HistoricalImportBatch
    {
        $batch = new HistoricalImportBatch();
        $batch->forceFill([
            'shop_id' => $shopId,
            'label' => 'FY 2023-24',
            'source_system' => 'Manual',
            'status' => HistoricalImportBatch::STATUS_DRAFT,
        ])->save();

        return $batch;
    }

    private function makeDocument(int $shopId, int $batchId, array $attrs = []): HistoricalSalesDocument
    {
        $number = $attrs['original_document_number'] ?? ('DOC-' . Str::random(8));
        $date = $attrs['document_date'] ?? '2026-03-15';
        $status = $attrs['status'] ?? HistoricalSalesDocument::STATUS_PUBLISHED;

        $document = new HistoricalSalesDocument();
        $document->forceFill(array_merge([
            'shop_id' => $shopId,
            'historical_import_batch_id' => $batchId,
            'historical_reference' => (string) Str::uuid(),
            'original_document_number' => $number,
            'original_document_number_normalized' => HistoricalDocumentIdentity::normalizeNumber($number),
            'document_type' => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'document_date' => $date,
            'financial_year' => HistoricalDocumentIdentity::financialYearFor(new \DateTimeImmutable($date)),
            'source_system' => 'Manual',
            'customer_snapshot' => ['name' => 'Ramesh Patel'],
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness' => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total' => 5000.00,
            'status' => $status,
            'content_fingerprint' => hash('sha256', (string) Str::uuid()),
            'published_at' => in_array($status, [
                HistoricalSalesDocument::STATUS_PUBLISHED,
                HistoricalSalesDocument::STATUS_SUPERSEDED,
            ], true) ? now() : null,
            'void_reason' => $status === HistoricalSalesDocument::STATUS_VOID ? 'Test fixture void' : null,
            'voided_at' => $status === HistoricalSalesDocument::STATUS_VOID ? now() : null,
        ], $attrs))->save();

        return $document;
    }

    // ---- request building ---------------------------------------------------

    private function definition(): ReportDefinition
    {
        return app(ReportRegistry::class)->definition(HistoricalSalesRegisterDataset::KEY);
    }

    private function keysFor(P $profile): array
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(false);

        return app(ColumnPolicy::class)
            ->resolve($this->definition(), $profile, $user, includeSensitive: false)
            ->columnKeys;
    }

    private function request(int $shopId, array $filters = [], P $profile = P::Detailed): ReportRequest
    {
        return new ReportRequest(
            definition: $this->definition(),
            shopId: $shopId,
            userId: 1,
            userName: 'Tester',
            profile: $profile,
            format: F::Csv,
            filters: $filters,
            columnKeys: $this->keysFor($profile),
        );
    }

    private function meta(): ReportMeta
    {
        return new ReportMeta(
            reportKey: HistoricalSalesRegisterDataset::KEY, reportVersion: HistoricalSalesRegisterDataset::VERSION,
            title: 'Historical Sales Register', profileLabel: 'Detailed', format: F::Csv->value,
            filtersApplied: [], periodLabel: null,
            shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null, shopStateCode: null,
            generatedByName: 'Tester', generatedAt: now(), generatorTag: 'test', watermark: null,
        );
    }

    private function build(int $shopId, ReportRequest $request)
    {
        return TenantContext::runFor($shopId, fn () => app(HistoricalSalesRegisterDataset::class)->build($request, $this->meta()));
    }

    // ---- §5 test 8: date range + status ------------------------------------

    public function test_lists_published_documents_filtered_by_document_date_range_and_status(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);

        $inRange = $this->makeDocument($shop->id, $batch->id, ['document_date' => '2026-03-10']);
        $this->makeDocument($shop->id, $batch->id, ['document_date' => '2026-01-05']); // out of range
        $this->makeDocument($shop->id, $batch->id, ['status' => HistoricalSalesDocument::STATUS_DRAFT]); // default excludes non-published
        $this->makeDocument($shop->id, $batch->id, ['status' => HistoricalSalesDocument::STATUS_VOID]); // default excludes non-published

        $request = $this->request($shop->id, [
            'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
        ]);
        $dataset = $this->build($shop->id, $request);

        $this->assertSame(1, $dataset->section('historical_sales_register')->rowCount());
        $this->assertSame(
            $inRange->displayNumber(),
            $dataset->section('historical_sales_register')->rows[0]['original_document_number']
        );
    }

    public function test_explicit_status_filter_widens_the_search_to_void_documents(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['status' => HistoricalSalesDocument::STATUS_VOID, 'document_date' => '2026-03-10']);

        $request = $this->request($shop->id, [
            'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
            'status' => HistoricalSalesDocument::STATUS_VOID,
        ]);
        $dataset = $this->build($shop->id, $request);

        $this->assertSame(1, $dataset->section('historical_sales_register')->rowCount());
    }

    // ---- §5 test 10: NULL outstanding never becomes ₹0 ----------------------

    public function test_null_outstanding_snapshot_is_blank_not_zero_and_excluded_from_the_total(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);

        $this->makeDocument($shop->id, $batch->id, [
            'document_date' => '2026-03-10', 'grand_total' => 5000, 'outstanding_amount_snapshot' => null,
        ]);
        $this->makeDocument($shop->id, $batch->id, [
            'document_date' => '2026-03-11', 'grand_total' => 5000, 'outstanding_amount_snapshot' => 500,
        ]);

        $request = $this->request($shop->id, [
            'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
        ]);
        $dataset = $this->build($shop->id, $request);
        $rows = $dataset->section('historical_sales_register')->rows;

        $this->assertNull($rows[0]['outstanding_amount_snapshot']);
        $this->assertSame(500.0, $dataset->section('historical_sales_register')->totals['outstanding_amount_snapshot']);
    }

    // ---- §3/§4: void/superseded never contribute to the totals row ---------

    public function test_void_documents_are_excluded_from_every_total(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);

        // A void document is visible when explicitly searched for (§3 audit trail)
        // but must never contribute a rupee to the totals row (§3/§4).
        $this->makeDocument($shop->id, $batch->id, [
            'document_date' => '2026-03-12', 'grand_total' => 99999, 'status' => HistoricalSalesDocument::STATUS_VOID,
        ]);

        $request = $this->request($shop->id, [
            'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
            'status' => HistoricalSalesDocument::STATUS_VOID,
        ]);
        $dataset = $this->build($shop->id, $request);

        $this->assertSame(1, $dataset->section('historical_sales_register')->rowCount());
        $this->assertSame(0.0, $dataset->section('historical_sales_register')->totals['grand_total']);
    }

    // ---- §5 test 9 (register-scoped): no write path -------------------------

    public function test_building_the_register_writes_nothing_to_live_tables(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['document_date' => '2026-03-10']);

        $before = collect(self::MONEY_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->build($shop->id, $this->request($shop->id, [
            'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
        ]));

        foreach (self::MONEY_TABLES as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "{$table} row count changed");
        }
    }

    // ---- §5 test 11: tenant isolation ----------------------------------------

    public function test_a_document_from_one_shop_never_appears_in_another_shops_register(): void
    {
        [, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $batchA = $this->makeBatch($shopA->id);
        $this->makeDocument($shopA->id, $batchA->id, ['document_date' => '2026-03-10']);

        $request = $this->request($shopB->id, [
            'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
        ]);
        $dataset = $this->build($shopB->id, $request);

        $this->assertSame(0, $dataset->section('historical_sales_register')->rowCount());
    }

    // ---- reference filter: partial, normalized search on the original number --

    public function test_reference_filter_finds_a_document_by_partial_original_number(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);

        $target = $this->makeDocument($shop->id, $batch->id, [
            'document_date' => '2026-03-10', 'original_document_number' => 'INV-2026-0100',
        ]);
        $this->makeDocument($shop->id, $batch->id, [
            'document_date' => '2026-03-11', 'original_document_number' => 'INV-2026-0200',
        ]);

        $request = $this->request($shop->id, [
            'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
            'reference' => '0100',
        ]);
        $dataset = $this->build($shop->id, $request);
        $rows = $dataset->section('historical_sales_register')->rows;

        $this->assertSame(1, $dataset->section('historical_sales_register')->rowCount());
        $this->assertSame($target->displayNumber(), $rows[0]['original_document_number']);
    }

    // ---- customer filter: free-text search, both linked and snapshot-only -----

    public function test_customer_filter_finds_a_document_by_snapshot_only_customer_name(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);

        $target = $this->makeDocument($shop->id, $batch->id, [
            'document_date' => '2026-03-10', 'customer_snapshot' => ['name' => 'Ramesh Patel'],
        ]);
        $this->makeDocument($shop->id, $batch->id, [
            'document_date' => '2026-03-11', 'customer_snapshot' => ['name' => 'Suresh Shah'],
        ]);

        $request = $this->request($shop->id, [
            'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
            'customer' => 'ramesh',
        ]);
        $dataset = $this->build($shop->id, $request);
        $rows = $dataset->section('historical_sales_register')->rows;

        $this->assertSame(1, $dataset->section('historical_sales_register')->rowCount());
        $this->assertSame($target->displayNumber(), $rows[0]['original_document_number']);
    }

    public function test_customer_filter_finds_a_document_by_linked_customer_name_or_mobile(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);

        $customer = TenantContext::runFor($shop->id, fn () => Customer::create([
            'first_name' => 'Suresh', 'last_name' => 'Shah', 'mobile' => '9876543210',
        ]));
        $target = $this->makeDocument($shop->id, $batch->id, [
            'document_date' => '2026-03-10', 'customer_id' => $customer->id, 'customer_snapshot' => [],
        ]);
        $this->makeDocument($shop->id, $batch->id, ['document_date' => '2026-03-11']); // Ramesh Patel snapshot, unrelated

        foreach (['suresh', '9876543210'] as $needle) {
            $request = $this->request($shop->id, [
                'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
                'customer' => $needle,
            ]);
            $dataset = $this->build($shop->id, $request);
            $rows = $dataset->section('historical_sales_register')->rows;

            $this->assertSame(1, $dataset->section('historical_sales_register')->rowCount(), "search '{$needle}' failed");
            $this->assertSame($target->displayNumber(), $rows[0]['original_document_number']);
        }
    }

    public function test_linked_customers_real_name_renders_in_the_customer_column(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);

        $customer = TenantContext::runFor($shop->id, fn () => Customer::create([
            'first_name' => 'Suresh', 'last_name' => 'Shah', 'mobile' => '9876543210',
        ]));
        $this->makeDocument($shop->id, $batch->id, [
            'document_date' => '2026-03-10', 'customer_id' => $customer->id,
            'customer_snapshot' => ['name' => 'Stale Snapshot Name'],
        ]);

        $request = $this->request($shop->id, ['period' => ['from' => '2026-03-01', 'to' => '2026-03-31']]);
        $dataset = $this->build($shop->id, $request);

        // The linked customer's real name wins over the (possibly stale) snapshot.
        $this->assertSame('Suresh Shah', $dataset->section('historical_sales_register')->rows[0]['customer']);
    }

    // ---- §9.1/§9.3: an all-unknown amount column reports "no known amounts", -
    // ---- never a silent ₹0 total ----------------------------------------------

    public function test_an_all_unknown_amount_column_is_disclosed_not_totalled_as_zero(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $batch = $this->makeBatch($shop->id);

        // taxable_amount is left unset -> NULL, same as every other snapshot field
        // makeDocument() doesn't default (§9.1: NULL means "source didn't say").
        $this->makeDocument($shop->id, $batch->id, ['document_date' => '2026-03-10']);

        $request = $this->request($shop->id, [
            'period' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
        ]);
        $dataset = $this->build($shop->id, $request);

        $this->assertArrayNotHasKey('taxable_amount', $dataset->section('historical_sales_register')->totals);

        $notes = $dataset->section('historical_sales_register_notes');
        $this->assertNotNull($notes);
        $taxableNote = collect($notes->rows)->firstWhere('metric', 'Taxable Value');
        $this->assertNotNull($taxableNote);
        $this->assertStringContainsString('no known Taxable Value amounts to total', $taxableNote['detail']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

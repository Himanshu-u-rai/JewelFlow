<?php

namespace Tests\Feature\Reporting;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Reports\DuesAgingDataset;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 4 §6 steps 2/4, §9.7.2 — the `sales_source` mode selector wired into
 * `DuesAgingDataset`. Covers: LIVE stays byte-identical (§5 test 1), HISTORICAL
 * mode actually calls the historical query and renders the disclosure notes
 * section, and any unrecognised value (including `combined`, still blocked by
 * §7.5/§7.6) falls back to LIVE rather than guessing.
 */
class DuesAgingModeTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function invoice(int $shopId, int $customerId, float $total, int $ageDays): int
    {
        $dt = \Carbon\Carbon::now()->subDays($ageDays)->setTime(10, 0);

        return (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => $total, 'discount' => 0, 'gst' => 0, 'gst_rate' => 0,
            'total' => $total, 'status' => Invoice::STATUS_FINALIZED,
            'created_at' => $dt, 'updated_at' => $dt, 'finalized_at' => $dt,
        ]);
    }

    private function makeBatch(int $shopId): HistoricalImportBatch
    {
        $batch = new HistoricalImportBatch;
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
        $number = $attrs['original_document_number'] ?? ('DOC-'.Str::random(8));
        $date = $attrs['document_date'] ?? '2026-03-15';
        $status = $attrs['status'] ?? HistoricalSalesDocument::STATUS_PUBLISHED;

        $document = new HistoricalSalesDocument;
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
            'outstanding_amount_snapshot' => 5000.00,
            'status' => $status,
            'content_fingerprint' => hash('sha256', (string) Str::uuid()),
            'published_at' => in_array($status, [
                HistoricalSalesDocument::STATUS_PUBLISHED,
                HistoricalSalesDocument::STATUS_SUPERSEDED,
            ], true) ? now() : null,
        ], $attrs))->save();

        return $document;
    }

    private function definition(): ReportDefinition
    {
        return app(ReportRegistry::class)->definition(DuesAgingDataset::KEY);
    }

    private function keysFor(P $profile): array
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(false);

        return app(ColumnPolicy::class)
            ->resolve($this->definition(), $profile, $user, includeSensitive: false)
            ->columnKeys;
    }

    private function request(int $shopId, array $filters = []): ReportRequest
    {
        return new ReportRequest(
            definition: $this->definition(),
            shopId: $shopId,
            userId: 1,
            userName: 'Tester',
            profile: P::Detailed,
            format: F::Csv,
            filters: $filters,
            columnKeys: $this->keysFor(P::Detailed),
        );
    }

    private function meta(): ReportMeta
    {
        return new ReportMeta(
            reportKey: DuesAgingDataset::KEY, reportVersion: DuesAgingDataset::VERSION,
            title: 'Customer Dues Aging', profileLabel: 'Detailed', format: F::Csv->value,
            filtersApplied: [], periodLabel: null,
            shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null, shopStateCode: null,
            generatedByName: 'Tester', generatedAt: now(), generatorTag: 'test', watermark: null,
        );
    }

    private function build(int $shopId, ReportRequest $request)
    {
        return TenantContext::runFor($shopId, fn () => app(DuesAgingDataset::class)->build($request, $this->meta()));
    }

    /** §5 test 1: no `sales_source` filter at all → identical to pre-Batch-4 LIVE behavior. */
    public function test_default_mode_is_live_and_has_no_notes_section(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 1000, 10);

        $dataset = $this->build($shop->id, $this->request($shop->id));

        $this->assertEqualsWithDelta(1000.0, $dataset->section('aging')->totals['total'], 0.01);
        $this->assertNull($dataset->section('aging_historical_notes'), 'LIVE mode never renders the historical notes section');
    }

    /** An explicit `sales_source=live` behaves exactly like the default. */
    public function test_explicit_live_mode_matches_default(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 2500, 10);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'live']));

        $this->assertEqualsWithDelta(2500.0, $dataset->section('aging')->totals['total'], 0.01);
        $this->assertNull($dataset->section('aging_historical_notes'));
    }

    /** HISTORICAL mode reads historical documents, not live invoices. */
    public function test_historical_mode_reads_historical_documents_not_live_invoices(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 9000, 10); // must be invisible to historical mode
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 1500]);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'historical']));

        $this->assertEqualsWithDelta(1500.0, $dataset->section('aging')->totals['total'], 0.01,
            'only the historical document counts — the live invoice must not leak in');
    }

    /** §4/§9.2 dedup exclusions render as a visible notes section, never a silent drop. */
    public function test_historical_mode_discloses_dedup_exclusions_in_notes_section(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);

        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 1000]);
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => $c->id, 'outstanding_amount_snapshot' => 4000,
            'opening_balance_overlap' => true,
            'opening_balance_resolution' => HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED,
            'opening_balance_resolved_by' => $user->id, 'opening_balance_resolved_at' => now(),
        ]);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => null]);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'historical']));

        $notes = $dataset->section('aging_historical_notes');
        $this->assertNotNull($notes, 'exclusions must be disclosed, not silently dropped');
        $metrics = array_column($notes->rows, 'metric');
        $this->assertContains('Already in opening balance', $metrics);
        $this->assertContains('Unknown outstanding', $metrics);
    }

    /** Combined mode is deliberately not implemented (§7.5/§7.6) — falls back to LIVE, never guesses. */
    public function test_combined_mode_falls_back_to_live(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 750, 5);
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 9999]);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'combined']));

        $this->assertEqualsWithDelta(750.0, $dataset->section('aging')->totals['total'], 0.01,
            'combined is not implemented — must fall back to live, not silently include historical data');
        $this->assertNull($dataset->section('aging_historical_notes'));
    }

    /** Any other hand-crafted/unrecognised value also falls back to LIVE. */
    public function test_unrecognised_mode_falls_back_to_live(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 300, 5);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'bogus']));

        $this->assertEqualsWithDelta(300.0, $dataset->section('aging')->totals['total'], 0.01);
    }
}

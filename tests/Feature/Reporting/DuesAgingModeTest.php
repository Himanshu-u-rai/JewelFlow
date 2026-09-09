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
 * Batch 4 — the `sales_source` mode selector wired into `DuesAgingDataset`
 * (owner-agreed reporting semantics, superseding the earlier "combined is
 * blocked, falls back to live" design; see the dataset's class docblock).
 * Covers: LIVE stays byte-identical, HISTORICAL renders "Unpaid as Recorded"
 * with overlap classifications disclosed (never subtracted) and unlinked
 * customers in their own section, COMBINED renders Live and Historical as two
 * independent sections with separate subtotals and no grand total, and any
 * unrecognised value falls back to LIVE at the dataset layer as defense in
 * depth (the real reject-don't-guess gate is upstream input validation,
 * covered at the HTTP layer in DuesAgingHttpModeTest).
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

    /** No `sales_source` filter at all → identical to pre-Batch-4 LIVE behavior. */
    public function test_default_mode_is_live_and_has_no_notes_section(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 1000, 10);

        $dataset = $this->build($shop->id, $this->request($shop->id));

        $this->assertEqualsWithDelta(1000.0, $dataset->section('aging')->totals['total'], 0.01);
        $this->assertNull($dataset->section('aging_historical_notes'), 'LIVE mode never renders the historical notes section');
        $this->assertNull($dataset->section('aging_historical'), 'LIVE mode never renders a second, historical section');
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
        $this->assertSame('Historical — Unpaid as Recorded', $dataset->section('aging')->title);
    }

    /** Historical age columns are labelled "days since invoice date", never "days overdue". */
    public function test_historical_mode_relabels_age_columns_as_days_since_invoice_date(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 1000]);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'historical']));

        $labels = array_column($dataset->section('aging')->columns, 'label') ?: array_map(fn ($c) => $c->label, $dataset->section('aging')->columns);
        $this->assertContains('0–30 days since invoice date', $labels);
        $this->assertContains('Unpaid as Recorded', $labels);
    }

    /** §4/§9.2 overlap classifications render as a visible notes section, never a silent drop and never a subtraction. */
    public function test_historical_mode_discloses_overlap_classifications_without_subtracting_them(): void
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

        $this->assertEqualsWithDelta(5000.0, $dataset->section('aging')->totals['total'], 0.01,
            'the opening-balance-overlap-flagged document is counted in full, not subtracted');

        $notes = $dataset->section('aging_historical_notes');
        $this->assertNotNull($notes, 'overlap + unknown-outstanding must be disclosed, not silently dropped');
        $metrics = array_column($notes->rows, 'metric');
        $this->assertContains('May be in opening balance', $metrics);
        $this->assertContains('Unknown outstanding — totals incomplete', $metrics);
    }

    /** Snapshot-only/unlinked customers render in their own section, under their recorded identity. */
    public function test_historical_mode_renders_unlinked_customers_in_their_own_section(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 1000]);
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => null, 'outstanding_amount_snapshot' => 7000,
            'customer_snapshot' => ['name' => 'Walk-in Suresh'],
        ]);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'historical']));

        $unlinked = $dataset->section('aging_unlinked');
        $this->assertNotNull($unlinked);
        $this->assertSame(1, $unlinked->rowCount());
        $this->assertSame('Walk-in Suresh', $unlinked->rows[0]['customer']);
        $this->assertEqualsWithDelta(1000.0, $dataset->section('aging')->totals['total'], 0.01, 'the unlinked customer never merges into the linked section total');
    }

    /**
     * COMBINED — Live and Historical are two independent sections, each with
     * its own subtotal. There is no grand total anywhere in the dataset (the
     * render pipeline has no cross-section summing mechanism, and this method
     * never introduces one).
     */
    public function test_combined_mode_renders_live_and_historical_side_by_side_with_no_grand_total(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 750, 5);
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 9999]);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'combined']));

        $live = $dataset->section('aging_live');
        $historical = $dataset->section('aging_historical');
        $this->assertNotNull($live);
        $this->assertNotNull($historical);
        $this->assertSame('Live — By Customer', $live->title);
        $this->assertSame('Historical — Unpaid as Recorded', $historical->title);
        $this->assertEqualsWithDelta(750.0, $live->totals['total'], 0.01);
        $this->assertEqualsWithDelta(9999.0, $historical->totals['total'], 0.01);

        // No section anywhere sums the two — assert every section total individually
        // rather than any single combined figure existing.
        foreach ($dataset->sections as $section) {
            if (isset($section->totals['total'])) {
                $this->assertNotEqualsWithDelta(750.0 + 9999.0, $section->totals['total'], 0.01,
                    "section [{$section->key}] must never hold a live+historical grand total");
            }
        }

        $notes = $dataset->section('aging_historical_notes');
        $this->assertNotNull($notes, 'Combined mode always explains the side-by-side presentation');
        $this->assertSame('Notes — Combined Mode', $notes->title);
        $this->assertContains('Combined presentation', array_column($notes->rows, 'metric'));
    }

    public function test_combined_mode_also_surfaces_unlinked_historical_customers(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 100, 5);
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => null, 'outstanding_amount_snapshot' => 300,
            'customer_snapshot' => ['name' => 'Walk-in Combined'],
        ]);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'combined']));

        $unlinked = $dataset->section('aging_unlinked');
        $this->assertNotNull($unlinked);
        $this->assertSame('Walk-in Combined', $unlinked->rows[0]['customer']);
    }

    /** Any hand-crafted/unrecognised value falls back to LIVE at the dataset layer (defense in depth). */
    public function test_unrecognised_mode_falls_back_to_live(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 300, 5);

        $dataset = $this->build($shop->id, $this->request($shop->id, ['sales_source' => 'bogus']));

        $this->assertEqualsWithDelta(300.0, $dataset->section('aging')->totals['total'], 0.01);
    }
}

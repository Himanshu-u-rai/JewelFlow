<?php

namespace Tests\Feature\Reporting;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Reporting\ReceivablesService;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 4 — `ReceivablesService::historicalDuesAging()` (owner-agreed reporting
 * semantics, superseding the earlier §7.5/§7.6-blocks-exclusion design; see
 * `DuesAgingDataset`'s class docblock). This is the query alone: published-
 * only, document_date-aged, every overlap classification counted IN FULL
 * (disclosure, never subtraction), unlinked/snapshot-only customers surfaced
 * in their own bucket rather than dropped, unknown outstanding disclosed as
 * incomplete rather than coerced to zero.
 */
class HistoricalDuesAgingQueryTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
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
            'void_reason' => $status === HistoricalSalesDocument::STATUS_VOID ? 'Test fixture void' : null,
            'voided_at' => $status === HistoricalSalesDocument::STATUS_VOID ? now() : null,
        ], $attrs))->save();

        return $document;
    }

    private function query(int $shopId, ?Carbon $asOf = null): \App\Reporting\Data\DuesAgingData
    {
        return TenantContext::runFor($shopId, fn () => app(ReceivablesService::class)->historicalDuesAging($shopId, $asOf));
    }

    public function test_historical_mode_dues_aging_excludes_draft_void_and_superseded_documents(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);

        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'status' => HistoricalSalesDocument::STATUS_PUBLISHED, 'outstanding_amount_snapshot' => 1000]);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'status' => HistoricalSalesDocument::STATUS_DRAFT, 'outstanding_amount_snapshot' => 2000]);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'status' => HistoricalSalesDocument::STATUS_VOID, 'outstanding_amount_snapshot' => 3000]);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'status' => HistoricalSalesDocument::STATUS_SUPERSEDED, 'outstanding_amount_snapshot' => 4000]);

        $data = $this->query($shop->id);

        $this->assertEqualsWithDelta(1000.0, $data->totalOutstanding, 0.01, 'only the published document counts');
        $this->assertSame(1, $data->invoiceCount);
    }

    /** Snapshot-only (unlinked) customers are surfaced in their own section — never dropped, never merged into a linked customer's row. */
    public function test_unlinked_customers_are_surfaced_in_their_own_section_not_merged_or_dropped(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);

        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 1000]);
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => null, 'outstanding_amount_snapshot' => 9000,
            'customer_snapshot' => ['name' => 'Walk-in Suresh', 'mobile' => '9998887770'],
        ]);

        $data = $this->query($shop->id);

        $this->assertEqualsWithDelta(1000.0, $data->totalOutstanding, 0.01, 'linked-customer total excludes the unlinked document');
        $this->assertSame(1, $data->invoiceCount);
        $this->assertSame(1, $data->unlinkedDocumentCount, 'the walk-in document is counted, not silently dropped');
        $this->assertCount(1, $data->unlinkedRows);
        $this->assertSame('Walk-in Suresh', $data->unlinkedRows->first()->customer_name);
        $this->assertEqualsWithDelta(9000.0, $data->unlinkedRows->first()->total, 0.01);
    }

    public function test_null_outstanding_snapshot_is_disclosed_not_zero_and_excluded_from_sums(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);

        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 1000]);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => null]);

        $data = $this->query($shop->id);

        $this->assertEqualsWithDelta(1000.0, $data->totalOutstanding, 0.01, 'unknown outstanding never coerced to 0');
        $this->assertSame(1, $data->invoiceCount);
        $this->assertSame(1, $data->unknownOutstandingCount, 'a NULL outstanding is disclosed as unknown, making the total incomplete — not excluded silently');
    }

    public function test_fully_settled_document_is_not_a_due(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);

        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 0]);

        $data = $this->query($shop->id);

        $this->assertEqualsWithDelta(0.0, $data->totalOutstanding, 0.01);
        $this->assertSame(0, $data->invoiceCount);
        $this->assertSame(0, $data->unknownOutstandingCount, 'a known zero is not an unknown');
    }

    /**
     * Owner-agreed reversal of the old exclusion design: EVERY overlap
     * classification is counted in full — `included_in_opening_balance` and
     * unresolved overlaps are no longer subtracted, only disclosed via the
     * classification counters. This report reads no `CustomerOpeningBalance`,
     * so there is nothing to double-count against.
     */
    public function test_overlap_flagged_documents_are_counted_in_full_and_classified_for_disclosure_only(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);

        // Row 1: no overlap at all.
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => $c->id, 'outstanding_amount_snapshot' => 1000,
            'opening_balance_overlap' => false,
        ]);
        // Row 2: overlap, resolved SEPARATE.
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => $c->id, 'outstanding_amount_snapshot' => 2000,
            'opening_balance_overlap' => true,
            'opening_balance_resolution' => HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
            'opening_balance_resolved_by' => $user->id, 'opening_balance_resolved_at' => now(),
        ]);
        // Row 3: overlap, resolved INCLUDED — historically excluded, now counted in full.
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => $c->id, 'outstanding_amount_snapshot' => 4000,
            'opening_balance_overlap' => true,
            'opening_balance_resolution' => HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED,
            'opening_balance_resolved_by' => $user->id, 'opening_balance_resolved_at' => now(),
        ]);
        // Row 4: overlap, UNRESOLVED — historically excluded, now counted in full.
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => $c->id, 'outstanding_amount_snapshot' => 8000,
            'opening_balance_overlap' => true,
            'opening_balance_resolution' => null,
        ]);

        $data = $this->query($shop->id);

        $this->assertEqualsWithDelta(15000.0, $data->totalOutstanding, 0.01, 'all four rows (1000+2000+4000+8000) are counted — overlap is disclosure, not exclusion');
        $this->assertSame(4, $data->invoiceCount);
        $this->assertSame(1, $data->separateFromOpeningBalanceCount);
        $this->assertSame(1, $data->includedInOpeningBalanceCount);
        $this->assertSame(1, $data->unresolvedOverlapCount);
    }

    public function test_buckets_by_document_date_age(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);

        $asOf = Carbon::now();
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 1000, 'document_date' => $asOf->copy()->subDays(10)->toDateString()]);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 2000, 'document_date' => $asOf->copy()->subDays(45)->toDateString()]);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 3000, 'document_date' => $asOf->copy()->subDays(75)->toDateString()]);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 4000, 'document_date' => $asOf->copy()->subDays(400)->toDateString()]);

        $data = $this->query($shop->id, $asOf);

        $this->assertEqualsWithDelta(1000.0, $data->bucketCurrent, 0.01);
        $this->assertEqualsWithDelta(2000.0, $data->bucket3160, 0.01);
        $this->assertEqualsWithDelta(3000.0, $data->bucket6190, 0.01);
        $this->assertEqualsWithDelta(4000.0, $data->bucket90plus, 0.01, 'a years-old undated-in-spirit bill still buckets 90+ (§7.2 open question, not solved here)');
        $this->assertEqualsWithDelta(10000.0, $data->totalOutstanding, 0.01);
    }

    public function test_tenant_isolation_a_documents_dues_never_appear_in_shop_bs_report(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $cA = $this->createCustomer($shopA->id);
        $batchA = $this->makeBatch($shopA->id);

        $this->makeDocument($shopA->id, $batchA->id, ['customer_id' => $cA->id, 'outstanding_amount_snapshot' => 1000]);

        $dataB = $this->query($shopB->id);

        $this->assertEqualsWithDelta(0.0, $dataB->totalOutstanding, 0.01);
        $this->assertSame(0, $dataB->invoiceCount);
        $this->assertCount(0, $dataB->unlinkedRows);
    }

    public function test_tenant_isolation_unlinked_customers_are_also_shop_scoped(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $batchA = $this->makeBatch($shopA->id);

        $this->makeDocument($shopA->id, $batchA->id, [
            'customer_id' => null, 'outstanding_amount_snapshot' => 5000,
            'customer_snapshot' => ['name' => 'Shop A Walk-in'],
        ]);

        $dataB = $this->query($shopB->id);

        $this->assertCount(0, $dataB->unlinkedRows);
        $this->assertSame(0, $dataB->unlinkedDocumentCount);
    }
}

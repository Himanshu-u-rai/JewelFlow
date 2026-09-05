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
 * Batch 4 — `ReceivablesService::historicalDuesAging()` (§6 steps 2-4, §9.7.2,
 * §5 tests 2/3/10). This is the query alone: published-only, linked-customer-
 * only, document_date-aged, §4-deduped. No mode selector, no Combined mode —
 * those are separate, later wiring (§7.5/§7.6 leave Combined blocked).
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

    private function query(int $shopId): \App\Reporting\Data\DuesAgingData
    {
        return TenantContext::runFor($shopId, fn () => app(ReceivablesService::class)->historicalDuesAging($shopId)
        );
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

    public function test_historical_mode_dues_aging_excludes_documents_with_no_linked_customer(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);

        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 1000]);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => null, 'outstanding_amount_snapshot' => 9000]);

        $data = $this->query($shop->id);

        $this->assertEqualsWithDelta(1000.0, $data->totalOutstanding, 0.01, 'snapshot-only walk-in cannot be chased inside JewelFlow');
        $this->assertSame(1, $data->invoiceCount);
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
        $this->assertSame(1, $data->excludedUnknownOutstandingCount);
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
        $this->assertSame(0, $data->excludedUnknownOutstandingCount, 'a known zero is not an unknown');
    }

    public function test_dedup_rule_additive_states_are_added_and_ambiguous_states_are_excluded_and_disclosed(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);

        // Row 1 (§4): no overlap at all — additive.
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => $c->id, 'outstanding_amount_snapshot' => 1000,
            'opening_balance_overlap' => false,
        ]);
        // Row 2 (§4): overlap, resolved SEPARATE — additive. (The check
        // constraint on `historical_sales_documents` requires resolved_by/at
        // whenever a resolution is set — mirrors a real operator decision.)
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => $c->id, 'outstanding_amount_snapshot' => 2000,
            'opening_balance_overlap' => true,
            'opening_balance_resolution' => HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
            'opening_balance_resolved_by' => $user->id, 'opening_balance_resolved_at' => now(),
        ]);
        // Row 3 (§4): overlap, resolved INCLUDED — already counted in
        // CustomerOpeningBalance elsewhere; excluded, never added again.
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => $c->id, 'outstanding_amount_snapshot' => 4000,
            'opening_balance_overlap' => true,
            'opening_balance_resolution' => HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED,
            'opening_balance_resolved_by' => $user->id, 'opening_balance_resolved_at' => now(),
        ]);
        // Row 4 (§4): overlap, UNRESOLVED — never guessed into a total.
        $this->makeDocument($shop->id, $batch->id, [
            'customer_id' => $c->id, 'outstanding_amount_snapshot' => 8000,
            'opening_balance_overlap' => true,
            'opening_balance_resolution' => null,
        ]);

        $data = $this->query($shop->id);

        $this->assertEqualsWithDelta(3000.0, $data->totalOutstanding, 0.01, 'only rows 1+2 (1000+2000) are additive');
        $this->assertSame(2, $data->invoiceCount);
        $this->assertSame(1, $data->excludedIncludedInOpeningBalanceCount);
        $this->assertSame(1, $data->excludedUnresolvedOverlapCount);
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

        $data = TenantContext::runFor($shop->id, fn () => app(ReceivablesService::class)->historicalDuesAging($shop->id, $asOf)
        );

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
    }
}

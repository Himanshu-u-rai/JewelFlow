<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N6 (2026-11-13): publishing committed a `publishing` claim, did the work in its own transaction, and handed
 * the claim back in a `finally`. A process killed in between (timeout, crash) skips `finally`: the batch stayed
 * `publishing` for good, neither publishable nor editable. The claim is now taken inside the publish transaction,
 * so a dead publish rolls back to `review` with everything else.
 */
class HistoricalPublishClaimTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function reviewBatch(int $shopId): HistoricalImportBatch
    {
        return TenantContext::runFor($shopId, function () use ($shopId) {
            $batch = new HistoricalImportBatch();
            $batch->forceFill([
                'shop_id' => $shopId, 'label' => 'FY 2023-24', 'source_system' => 'Manual',
                'status' => HistoricalImportBatch::STATUS_REVIEW, 'preview_generated_at' => now(),
                'blocking_count' => 0, 'warning_count' => 0,
            ])->save();

            $number = 'DOC-' . Str::random(8);
            (new HistoricalSalesDocument())->forceFill([
                'shop_id' => $shopId, 'historical_import_batch_id' => $batch->id,
                'historical_reference' => (string) Str::uuid(),
                'original_document_number' => $number,
                'original_document_number_normalized' => HistoricalDocumentIdentity::normalizeNumber($number),
                'document_type' => HistoricalSalesDocument::TYPE_SALE_INVOICE, 'document_date' => '2023-11-04',
                'financial_year' => HistoricalDocumentIdentity::financialYearFor(new \DateTimeImmutable('2023-11-04')),
                'source_system' => 'Manual', 'customer_snapshot' => ['name' => 'Ramesh Patel'],
                'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN, 'tax_completeness' => HistoricalSalesDocument::TAX_UNKNOWN,
                'grand_total' => 25000.00, 'status' => HistoricalSalesDocument::STATUS_DRAFT,
                'content_fingerprint' => hash('sha256', (string) Str::uuid()),
            ])->save();

            return $batch;
        });
    }

    /** Transaction depth at which the `publishing` claim was written (the test itself runs at depth 1). */
    private function claimDepth(): \Closure
    {
        $depth = null;
        DB::listen(function ($query) use (&$depth) {
            if ($depth === null && str_starts_with(strtolower($query->sql), 'update "historical_import_batches"')
                && in_array(HistoricalImportBatch::STATUS_PUBLISHING, $query->bindings, true)) {
                $depth = DB::transactionLevel();
            }
        });

        return function () use (&$depth) { return $depth; };
    }

    private function stateOf(HistoricalImportBatch $batch): string
    {
        return DB::table('historical_import_batches')->where('id', $batch->id)->value('status');
    }

    public function test_the_batch_page_claims_inside_the_publish_transaction(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->reviewBatch($shop->id);
        $depth = $this->claimDepth();

        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertSessionHas('success');

        $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHED, $this->stateOf($batch));
        $this->assertGreaterThan(1, $depth(), 'a claim committed on its own outlives a dead publish');
    }

    public function test_the_service_publish_claims_inside_the_publish_transaction(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->reviewBatch($shop->id);
        $depth = $this->claimDepth();

        TenantContext::runFor($shop->id, fn () => app(HistoricalDocumentLifecycleService::class)->publish($batch, $owner->id));

        $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHED, $this->stateOf($batch));
        $this->assertGreaterThan(1, $depth());
    }

    public function test_a_failed_publish_leaves_the_batch_in_review_and_nothing_published(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->reviewBatch($shop->id);
        Event::listen('eloquent.saving: ' . HistoricalSalesDocument::class, fn () => throw new \RuntimeException('disk full'));

        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertSessionHas('error');

        $this->assertSame(HistoricalImportBatch::STATUS_REVIEW, $this->stateOf($batch));
        $this->assertSame(0, DB::table('historical_sales_documents')->where('historical_import_batch_id', $batch->id)
            ->where('status', HistoricalSalesDocument::STATUS_PUBLISHED)->count());
    }

    public function test_the_migration_returns_a_stranded_batch_to_review(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $batch = $this->reviewBatch($shop->id);
        DB::table('historical_import_batches')->where('id', $batch->id)->update(['status' => HistoricalImportBatch::STATUS_PUBLISHING]);

        (require base_path('database/migrations/2026_11_14_100000_release_stranded_historical_publishing_claims.php'))->up();

        $this->assertSame(HistoricalImportBatch::STATUS_REVIEW, $this->stateOf($batch));
    }
}

<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3 UI fix — the `/historical` landing page render. Prior to this, the
 * page used an ad-hoc `max-width:1100px` wrapper instead of the app's real
 * `content-inner` shell, which is what made it look cramped next to every
 * other list page. These tests pin down the content that must survive the
 * layout rewrite: exact original numbers, status/tax text, working links,
 * empty states, tenant isolation, and — since this page renders raw model
 * data straight from the historical tables — that no internal id/reference
 * ever leaks into the HTML.
 */
class HistoricalIndexRenderTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function seedBatchAndDocument(int $shopId, int $actorId, array $batchOverride = [], array $docOverride = []): array
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $actorId, $batchOverride, $docOverride): array {
            $batch = new HistoricalImportBatch;
            $batch->forceFill(array_merge([
                'shop_id' => $shopId,
                'label' => 'FY 2022-23 ledger',
                'source_system' => 'Tally',
                // This fixture hand-rolls the row instead of going through
                // createBatchFromUpload(), so it has to stamp the one column that
                // makes a batch a file import. Without it the batch is structurally
                // manual (isManualBatch() === source_file_name === null) no matter
                // what its label and source_system say, and the Import-batches
                // register correctly refuses to list it. The label is not the
                // discriminator — the file name is.
                'source_file_name' => 'fy-2022-23-tally.csv',
                'status' => HistoricalImportBatch::STATUS_REVIEW,
                'created_by' => $actorId,
            ], $batchOverride))->save();

            $doc = new HistoricalSalesDocument;
            $doc->forceFill(array_merge([
                'shop_id' => $shopId,
                'historical_import_batch_id' => $batch->id,
                'historical_reference' => (string) Str::uuid(),
                'original_document_number' => 'INV/2022-23/0042',
                'original_document_number_normalized' => 'INV/2022-23/0042',
                'document_type' => HistoricalSalesDocument::TYPE_SALE_INVOICE,
                'document_date' => '2022-11-04',
                'financial_year' => '2022-23',
                'source_system' => 'Tally',
                'customer_snapshot' => ['name' => 'Ramesh Patel'],
                'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
                'tax_completeness' => HistoricalSalesDocument::TAX_COMPLETE,
                'grand_total' => 25000.00,
                'status' => HistoricalSalesDocument::STATUS_DRAFT,
                'content_fingerprint' => str_repeat('a', 64),
            ], $docOverride))->save();

            return [$batch, $doc];
        });
    }

    public function test_index_shows_exact_original_number_status_and_tax_text(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch, $document] = $this->seedBatchAndDocument($shop->id, $owner->id);

        $response = $this->actingAs($owner)->get(route('historical.index'));

        $response->assertOk();
        $response->assertSee('INV/2022-23/0042', false);
        $response->assertSee(HistoricalSalesDocument::BADGE, false);
        $response->assertSee('Ramesh Patel', false);
        $response->assertSee('₹25,000.00', false);
        $response->assertSee('Tax complete', false);
        $response->assertSee('Draft', false);
        $response->assertSee($batch->label, false);
        $response->assertSee('Review', false);
    }

    public function test_index_links_to_document_and_batch_detail_routes(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch, $document] = $this->seedBatchAndDocument($shop->id, $owner->id);

        $response = $this->actingAs($owner)->get(route('historical.index'));

        $response->assertOk();
        $response->assertSee(route('historical.documents.show', $document), false);
        $response->assertSee(route('historical.batches.show', $batch), false);
    }

    public function test_index_shows_empty_states_when_nothing_is_recorded_yet(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->get(route('historical.index'));

        $response->assertOk();
        $response->assertSee('No file imports yet', false);
        $response->assertSee('Import a CSV or XLSX file to begin.', false);
        $response->assertSee('No historical documents yet', false);
    }

    public function test_index_never_leaks_the_internal_reference_uuid_or_fingerprint(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $document] = $this->seedBatchAndDocument($shop->id, $owner->id);

        $response = $this->actingAs($owner)->get(route('historical.index'));

        $response->assertOk();
        $response->assertDontSee($document->historical_reference, false);
        $response->assertDontSee($document->content_fingerprint, false);
    }

    public function test_index_is_tenant_isolated(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $this->seedBatchAndDocument($shopA->id, $ownerA->id, ['label' => 'Shop A ledger'], [
            'original_document_number' => 'SHOPA-0001',
            'original_document_number_normalized' => 'SHOPA-0001',
        ]);
        $this->seedBatchAndDocument($shopB->id, $ownerB->id, ['label' => 'Shop B ledger'], [
            'original_document_number' => 'SHOPB-0001',
            'original_document_number_normalized' => 'SHOPB-0001',
        ]);

        $response = $this->actingAs($ownerA)->get(route('historical.index'));

        $response->assertOk();
        $response->assertSee('SHOPA-0001', false);
        $response->assertSee('Shop A ledger', false);
        $response->assertDontSee('SHOPB-0001', false);
        $response->assertDontSee('Shop B ledger', false);
    }
}

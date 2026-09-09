<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3, Commit 2 — the document detail page's void/supersede "danger zone".
 * Both routes and their allow-listed writes already existed and were already
 * proven at the service layer (HistoricalSalesFoundationTest #14/#14b). What
 * had never been proven end to end: the UI only offers these actions on a
 * published document to a historical.publish user, never on a draft or to an
 * import-only user, and once used the lifecycle (revises / superseded by) is
 * actually rendered on both sides of the relationship.
 */
class HistoricalDocumentLifecycleActionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeBatch(int $shopId): HistoricalImportBatch
    {
        $batch = new HistoricalImportBatch();
        $batch->forceFill([
            'shop_id'       => $shopId,
            'label'         => 'FY 2023-24',
            'source_system' => 'Manual',
            'status'        => HistoricalImportBatch::STATUS_DRAFT,
        ])->save();

        return $batch;
    }

    private function makeDocument(int $shopId, int $batchId, array $attrs = []): HistoricalSalesDocument
    {
        $number     = $attrs['original_document_number'] ?? ('DOC-' . Str::random(8));
        $date       = $attrs['document_date'] ?? '2023-11-04';
        $normalized = HistoricalDocumentIdentity::normalizeNumber($number);
        $fy         = HistoricalDocumentIdentity::financialYearFor(new \DateTimeImmutable($date));

        $status = $attrs['status'] ?? HistoricalSalesDocument::STATUS_DRAFT;

        $document = new HistoricalSalesDocument();
        $document->forceFill(array_merge([
            'shop_id'                              => $shopId,
            'historical_import_batch_id'           => $batchId,
            'historical_reference'                 => (string) Str::uuid(),
            'original_document_number'             => $number,
            'original_document_number_normalized'  => $normalized,
            'document_type'                        => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'document_date'                         => $date,
            'financial_year'                        => $fy,
            'source_system'                         => 'Manual',
            'customer_snapshot'                     => ['name' => 'Ramesh Patel'],
            'tax_mode'                               => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness'                       => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total'                            => 25000.00,
            'status'                                 => HistoricalSalesDocument::STATUS_DRAFT,
            'content_fingerprint'                    => hash('sha256', (string) Str::uuid()),
            // The DB check constraint requires published_at whenever status is
            // published/superseded — mirror that here so fixtures for either
            // status don't need to repeat it themselves.
            'published_at' => in_array($status, [
                HistoricalSalesDocument::STATUS_PUBLISHED,
                HistoricalSalesDocument::STATUS_SUPERSEDED,
            ], true) ? now() : null,
        ], $attrs))->save();

        return $document;
    }

    // ------------------------------------------------------------------ void

    public function test_void_form_is_hidden_on_a_draft_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            return $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id);
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $response->assertOk();
        $response->assertDontSee(route('historical.documents.void', $document), false);
    }

    public function test_void_form_is_hidden_without_publish_permission(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            return $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id, [
                'status' => HistoricalSalesDocument::STATUS_PUBLISHED,
            ]);
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $response->assertOk();
        $response->assertDontSee(route('historical.documents.void', $document), false);

        $this->actingAs($owner)
            ->post(route('historical.documents.void', $document), ['reason' => 'test'])
            ->assertForbidden();
    }

    public function test_publish_user_can_void_a_published_document_and_the_reason_is_shown(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            return $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id, [
                'status' => HistoricalSalesDocument::STATUS_PUBLISHED,
            ]);
        });

        $view = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $view->assertOk();
        $view->assertSee(route('historical.documents.void', $document), false);

        $this->actingAs($owner)
            ->post(route('historical.documents.void', $document), ['reason' => 'Data entry error'])
            ->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($document) {
            $fresh = $document->fresh();
            $this->assertSame(HistoricalSalesDocument::STATUS_VOID, $fresh->status);
            $this->assertSame('Data entry error', $fresh->void_reason);
            $this->assertNotNull($fresh->voided_at);
        });

        $after = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $after->assertOk();
        $after->assertSee('Data entry error');
    }

    // ------------------------------------------------------------ supersede

    public function test_supersede_form_is_hidden_on_a_draft_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            return $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id);
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $response->assertOk();
        $response->assertDontSee(route('historical.documents.supersede', $document), false);
    }

    public function test_supersede_form_is_hidden_without_publish_permission(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            return $this->makeDocument($shop->id, $this->makeBatch($shop->id)->id, [
                'status' => HistoricalSalesDocument::STATUS_PUBLISHED,
            ]);
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $response->assertOk();
        $response->assertDontSee(route('historical.documents.supersede', $document), false);
    }

    public function test_publish_user_can_supersede_and_both_sides_render_the_lifecycle_link(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        [$original, $replacement] = TenantContext::runFor($shop->id, function () use ($shop) {
            $batch       = $this->makeBatch($shop->id);
            $original    = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'INV-100',
                'status'                   => HistoricalSalesDocument::STATUS_PUBLISHED,
            ]);
            $replacement = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'INV-100-CORRECTED',
            ]);

            return [$original, $replacement];
        });

        $view = $this->actingAs($owner)->get(route('historical.documents.show', $original->id));
        $view->assertOk();
        $view->assertSee(route('historical.documents.supersede', $original), false);

        $this->actingAs($owner)
            ->post(route('historical.documents.supersede', $original), [
                'replacement_id' => $replacement->id,
                'reason'         => 'Corrected amount',
            ])
            ->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($original, $replacement) {
            $freshOriginal    = $original->fresh();
            $freshReplacement = $replacement->fresh();

            $this->assertSame(HistoricalSalesDocument::STATUS_SUPERSEDED, $freshOriginal->status);
            $this->assertSame($replacement->id, $freshOriginal->superseded_by_document_id);
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $freshReplacement->status);
            $this->assertSame($original->id, $freshReplacement->revises_document_id);
        });

        $originalPage = $this->actingAs($owner)->get(route('historical.documents.show', $original->id));
        $originalPage->assertOk();
        $originalPage->assertSee('Superseded by');
        $originalPage->assertSee('INV-100-CORRECTED');

        $replacementPage = $this->actingAs($owner)->get(route('historical.documents.show', $replacement->id));
        $replacementPage->assertOk();
        $replacementPage->assertSee('Revises');
        $replacementPage->assertSee('INV-100');
    }
}

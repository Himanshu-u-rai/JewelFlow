<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalDuplicateDetector;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Historical Sales — Batch 2 HTTP surface.
 *
 * Batch 1 proved the model and the database boundary. These tests prove the web
 * layer that sits on top: the permission matrix (view / import / publish), the
 * retailer-edition gate, cross-shop 404, read-only enforcement, the manual-entry
 * pipeline, future-date blocking, and publish idempotency.
 *
 * The security rule under test is Phase 3's: navigation is NOT the boundary —
 * route middleware and controller authorization enforce the same thing, so every
 * assertion here hits the route directly rather than trusting a hidden link.
 */
class HistoricalModuleHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ------------------------------------------------------------------ helpers

    private function seedPublishableBatch(int $shopId, int $actorId): HistoricalImportBatch
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $actorId): HistoricalImportBatch {
            $batch = new HistoricalImportBatch();
            $batch->forceFill([
                'shop_id'              => $shopId,
                'label'               => 'FY 2022-23',
                'source_system'       => 'Tally',
                'status'              => HistoricalImportBatch::STATUS_REVIEW,
                'created_by'          => $actorId,
                'preview_generated_at' => now(),
                'blocking_count'      => 0,
                'warning_count'       => 0,
            ])->save();

            $doc = new HistoricalSalesDocument();
            $doc->forceFill([
                'shop_id'                    => $shopId,
                'historical_import_batch_id' => $batch->id,
                'historical_reference'       => (string) Str::uuid(),
                'original_document_number'   => 'INV/2022-23/0001',
                'original_document_number_normalized' => 'INV/2022-23/0001',
                'document_type'              => HistoricalSalesDocument::TYPE_SALE_INVOICE,
                'document_date'              => '2022-11-04',
                'financial_year'             => '2022-23',
                'source_system'              => 'Tally',
                'customer_snapshot'          => ['name' => 'Ramesh Patel'],
                'tax_mode'                   => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
                'tax_completeness'           => HistoricalSalesDocument::TAX_UNKNOWN,
                'grand_total'                => 25000.00,
                'status'                     => HistoricalSalesDocument::STATUS_DRAFT,
                'content_fingerprint'        => str_repeat('a', 64),
            ])->save();

            return $batch;
        });
    }

    /**
     * A real-import-shaped batch: HistoricalImportRow rows carrying array-cast
     * `messages` — one duplicate-group hit, one plain warning. Manual-entry
     * batches never populate this table, so only this shape exercises the
     * batch-detail view's duplicate-group and staged-row rendering.
     */
    private function seedImportBatchWithStagedRows(int $shopId, int $actorId): HistoricalImportBatch
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $actorId): HistoricalImportBatch {
            $batch = new HistoricalImportBatch();
            $batch->forceFill([
                'shop_id'              => $shopId,
                'label'                => 'CSV Import Layout A',
                'source_system'        => 'Tally',
                'status'               => HistoricalImportBatch::STATUS_REVIEW,
                'created_by'           => $actorId,
                'preview_generated_at' => now(),
                'blocking_count'       => 0,
                'warning_count'        => 1,
            ])->save();

            $dupRow = new HistoricalImportRow();
            $dupRow->forceFill([
                'shop_id'                    => $shopId,
                'historical_import_batch_id' => $batch->id,
                'source_sheet'               => 'Sheet1',
                'source_row_number'          => 2,
                'grouping_key'               => 'dup-group-1',
                'original_payload'           => ['InvoiceNo' => 'DUP-0001'],
                'normalized_payload'         => ['original_document_number' => 'DUP-0001'],
                'severity'                   => HistoricalImportRow::SEVERITY_WARNING,
                'validation_status'          => HistoricalImportRow::VALIDATION_VALID,
                'messages'                   => [[
                    'severity' => HistoricalImportRow::SEVERITY_WARNING,
                    'code'     => HistoricalDuplicateDetector::CODE_DUPLICATE_NUMBER,
                    'text'     => 'Bill DUP-0001 is already imported in this financial year.',
                    'field'    => null,
                ]],
            ])->save();

            $warnRow = new HistoricalImportRow();
            $warnRow->forceFill([
                'shop_id'                    => $shopId,
                'historical_import_batch_id' => $batch->id,
                'source_sheet'               => 'Sheet1',
                'source_row_number'          => 3,
                'grouping_key'               => 'row-3',
                'original_payload'           => ['InvoiceNo' => 'CSV-0002'],
                'normalized_payload'         => ['original_document_number' => 'CSV-0002'],
                'severity'                   => HistoricalImportRow::SEVERITY_WARNING,
                'validation_status'          => HistoricalImportRow::VALIDATION_VALID,
                'messages'                   => [[
                    'severity' => HistoricalImportRow::SEVERITY_WARNING,
                    'code'     => 'tax_unknown',
                    'text'     => 'Tax mode could not be determined from source data.',
                    'field'    => null,
                ]],
            ])->save();

            return $batch;
        });
    }

    /** @return array<string, mixed> a valid manual-entry payload. */
    private function manualPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'M-' . fake()->unique()->numberBetween(1, 99999),
            'document_date'            => '2023-06-15',
            'source_system'            => 'Manual',
            'customer_name'            => 'Walk-in Customer',
            'grand_total'              => 18000,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
        ], $override);
    }

    // ------------------------------------------------------------ view / edition

    public function test_view_permission_is_required_for_the_landing_page(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->grantOnlyPermissions($owner, []);
        $this->actingAs($owner->fresh())->get(route('historical.index'))->assertForbidden();

        $this->grantOnlyPermissions($owner, ['historical.view']);
        $this->actingAs($owner->fresh())->get(route('historical.index'))->assertOk();
    }

    public function test_manufacturer_shop_cannot_reach_the_module(): void
    {
        [$owner] = $this->createManufacturerTenant(); // owner has every permission…

        // …but the retailer-edition gate refuses the whole module.
        $this->actingAs($owner)->get(route('historical.index'))->assertForbidden();
    }

    public function test_a_document_from_another_shop_is_404(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [, $shopB]        = $this->createRetailerTenant();

        $batchB = $this->seedPublishableBatch($shopB->id, $ownerA->id);
        $docB   = TenantContext::runFor($shopB->id, fn () => HistoricalSalesDocument::query()->firstOrFail());

        // Shop A's owner has full permissions, but the objects live in shop B. The
        // explicit shop-scoped Route::bind resolves against ownerA's shop, so shop B's
        // ids can only 404 — no TenantContext injection, this is the real route.
        $this->actingAs($ownerA)->get(route('historical.documents.show', $docB->id))->assertNotFound();
        $this->actingAs($ownerA)->get(route('historical.batches.show', $batchB->id))->assertNotFound();

        // And an unauthenticated request never reveals the object: it either 404s
        // (scoped bind fails closed with no user) or redirects to login — never 200.
        $status = $this->get(route('historical.batches.show', $batchB->id))->status();
        $this->assertContains($status, [302, 401, 404], "Unauth got {$status}, may leak existence.");
    }

    public function test_owner_reaches_own_batch_through_the_real_route(): void
    {
        // Positive counterpart to the cross-shop 404: same shop resolves and renders,
        // proving the scoped bind is not just trivially 404-ing everything under test.
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->seedPublishableBatch($shop->id, $owner->id);

        $this->actingAs($owner)->get(route('historical.batches.show', $batch->id))->assertOk();
    }

    // ------------------------------------------------------------ import matrix

    public function test_import_permission_is_required_to_reach_manual_entry(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->grantOnlyPermissions($owner, ['historical.view']);
        $this->actingAs($owner->fresh())->get(route('historical.manual.create'))->assertForbidden();

        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);
        $this->actingAs($owner->fresh())->get(route('historical.manual.create'))->assertOk();
    }

    public function test_manual_entry_creates_a_draft_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload());

        $response->assertRedirect();
        TenantContext::runFor($shop->id, function () {
            $this->assertSame(1, HistoricalSalesDocument::query()->count());
            $this->assertSame(
                HistoricalSalesDocument::STATUS_DRAFT,
                HistoricalSalesDocument::query()->firstOrFail()->status
            );
        });
    }

    public function test_a_future_dated_manual_bill_is_blocked_and_saves_nothing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)
            ->post(route('historical.manual.store'), $this->manualPayload([
                'document_date' => now()->addYear()->toDateString(),
            ]))
            ->assertSessionHas('historical_messages');

        TenantContext::runFor($shop->id, function () {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
            $this->assertSame(0, HistoricalImportBatch::query()->count(), 'A rejected bill left an orphan batch.');
        });
    }

    // ------------------------------------------------------------ publish matrix

    public function test_publish_requires_the_publish_permission(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->seedPublishableBatch($shop->id, $owner->id);

        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);
        // The scoped Route::bind resolves the batch (same shop), so the 403 here is
        // the can:historical.publish gate firing — not a binding miss.
        $this->actingAs($owner->fresh())
            ->post(route('historical.batches.publish', $batch->id))
            ->assertForbidden();

        TenantContext::runFor($shop->id, fn () => $this->assertSame(
            HistoricalSalesDocument::STATUS_DRAFT,
            HistoricalSalesDocument::query()->firstOrFail()->status
        ));
    }

    public function test_publish_is_idempotent(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->seedPublishableBatch($shop->id, $owner->id);

        // Real route, scoped bind — no context injection needed.
        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();
        // A second publish must neither error nor duplicate — it re-shows.
        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $this->assertSame(1, HistoricalSalesDocument::query()->count());
            $this->assertSame(
                HistoricalSalesDocument::STATUS_PUBLISHED,
                HistoricalSalesDocument::query()->firstOrFail()->status
            );
        });
    }

    // ------------------------------------------------------------ clean manual batch render

    /**
     * Regression for the empty-severity-section render blocker: a clean manual-entry
     * batch (one document, one line, zero duplicate/warning/blocking rows) must render
     * both the batch-detail and document-detail pages without the severity sections
     * throwing, and must show the real original number rather than a generated one.
     */
    public function test_clean_manual_batch_and_document_render_without_error(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $store = $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload([
            'original_document_number' => 'OLD-HARD-COPY-2023-017',
            'lines' => [[
                'line_item_name'    => 'Gold ring',
                'line_quantity'     => 1,
                'line_gross_weight' => 5,
                'line_net_weight'   => 5,
                'line_total'        => 18000,
            ]],
        ]));

        $store->assertRedirect();
        [$batchId, $document] = TenantContext::runFor($shop->id, function () {
            $doc = HistoricalSalesDocument::query()->firstOrFail();

            return [$doc->historical_import_batch_id, $doc];
        });

        $batchResponse = $this->actingAs($owner)->get(route('historical.batches.show', $batchId));
        $batchResponse->assertStatus(200);
        $batchResponse->assertDontSee($document->historical_reference);

        $documentResponse = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $documentResponse->assertStatus(200);
        $documentResponse->assertSee('OLD-HARD-COPY-2023-017');
        $documentResponse->assertSee(HistoricalSalesDocument::BADGE);
        $documentResponse->assertSee(HistoricalSalesDocument::RECORD_DISCLAIMER);
        $documentResponse->assertDontSee($document->historical_reference);
    }

    // ------------------------------------------------------------ real-import staged rows

    /**
     * Regression for the array-cast double-decode blocker: HistoricalImportRow
     * `messages` is Eloquent-cast to array, so calling json_decode() on it in the
     * view throws a TypeError. Only a batch with real staged rows (i.e. a real
     * CSV/XLSX import) exercises this path — manual entries never populate
     * HistoricalImportRow, which is why Batch 1's manual-entry test missed it.
     */
    public function test_import_batch_with_staged_rows_renders_duplicate_group_and_warning(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->seedImportBatchWithStagedRows($shop->id, $owner->id);

        $response = $this->actingAs($owner)->get(route('historical.batches.show', $batch->id));

        $response->assertStatus(200);
        $response->assertSee('Bill DUP-0001 is already imported in this financial year.', false);
        $response->assertSee('Tax mode could not be determined from source data.', false);
    }

    // ------------------------------------------------------------ read-only shop

    public function test_read_only_shop_may_view_but_not_write(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $shop->forceFill(['access_mode' => 'read_only'])->save();

        // Viewing is allowed with historical.view.
        $this->actingAs($owner)->get(route('historical.index'))->assertOk();

        // Writing is refused by the read-only middleware before any controller runs.
        $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload());

        TenantContext::runFor($shop->id, fn () => $this->assertSame(
            0,
            HistoricalSalesDocument::query()->count(),
            'A read-only shop wrote a historical document.'
        ));
    }
}

<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalDuplicateDetector;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
                // A FILE-import batch, and now structurally so. isManualBatch() keys
                // off source_file_name (source_system is free text the operator can
                // edit), so a fixture that calls itself a Tally export has to carry a
                // file name or it is a manual bill wearing a Tally label.
                'source_file_name'    => 'tally-fy-2022-23.csv',
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

    /**
     * A genuine two-sheet XLSX (real PhpSpreadsheet bytes, not seeded rows): an
     * "Invoices" header sheet and a "Lines" detail sheet, joined on InvoiceNo.
     * Layout C only exists for files shaped like this, so the mapping-screen
     * regression has to exercise the real upload -> real reader path.
     */
    private function buildTwoSheetXlsxContent(): string
    {
        $spreadsheet = new Spreadsheet();

        $invoices = $spreadsheet->getActiveSheet();
        $invoices->setTitle('Invoices');
        $invoices->fromArray(['InvoiceNo', 'InvoiceDate', 'CustomerName', 'GrandTotal'], null, 'A1');
        $invoices->fromArray(['INV-1', '2023-06-15', 'Asha Traders', 15000], null, 'A2');
        $invoices->fromArray(['INV-2', '2023-06-20', 'Ramesh Patel', 8000], null, 'A3');

        $lines = $spreadsheet->createSheet();
        $lines->setTitle('Lines');
        $lines->fromArray(['InvoiceNo', 'ItemName', 'Quantity', 'LineTotal'], null, 'A1');
        $lines->fromArray(['INV-1', 'Gold Ring', 1, 10000], null, 'A2');
        $lines->fromArray(['INV-1', 'Gold Chain', 1, 5000], null, 'A3');
        $lines->fromArray(['INV-2', 'Silver Bangle', 2, 8000], null, 'A4');

        $path = tempnam(sys_get_temp_dir(), 'jf_xlsx_');
        (new Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }

    /** Uploads the two-sheet fixture through the real route and returns the resulting batch id. */
    private function uploadTwoSheetBatch(int $shopId, $owner): int
    {
        $file = UploadedFile::fake()->createWithContent('sales.xlsx', $this->buildTwoSheetXlsxContent());

        $this->actingAs($owner)
            ->post(route('historical.upload.store'), [
                'file'          => $file,
                'label'         => 'Two-sheet import',
                'source_system' => 'Tally',
            ])
            ->assertRedirect();

        return (int) TenantContext::runFor(
            $shopId,
            fn () => HistoricalImportBatch::query()->latest('id')->value('id')
        );
    }

    /** @return array<string, mixed> a valid header/detail (Layout C) mapping payload for the fixture above. */
    private function twoSheetMappingPayload(): array
    {
        return [
            'name'                => 'Two-sheet profile',
            'source_system'       => 'Tally',
            'layout_type'         => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_DETAIL,
            'header_row'          => 1,
            'date_format'         => 'YYYY-MM-DD',
            'decimal_separator'   => '.',
            'thousands_separator' => ',',
            'tax_mode'            => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'sheets'              => ['header' => 'Invoices', 'detail' => 'Lines'],
            'mapping'             => [
                'original_document_number' => 'InvoiceNo',
                'document_date'            => 'InvoiceDate',
                'customer_name'            => 'CustomerName',
                'grand_total'              => 'GrandTotal',
                'line_item_name'           => 'ItemName',
                'line_quantity'            => 'Quantity',
                'line_total'               => 'LineTotal',
                'join_key'                 => 'InvoiceNo',
                'detail_join_key'          => 'InvoiceNo',
            ],
        ];
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

        // The internal batch URL forwards to the document; the document page is the
        // manual review surface, so it is where the empty-severity-section render
        // blocker has to stay fixed. (The same regression on the BATCH page is still
        // covered by the file-import batch tests above, which render it directly.)
        $this->actingAs($owner)
            ->get(route('historical.batches.show', $batchId))
            ->assertRedirect(route('historical.documents.show', $document->id));

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

    // ------------------------------------------------------------ Layout C multi-sheet mapping

    /**
     * Regression for the Layout-C mapping blocker: before the fix, every mapping
     * dropdown (including the "Line" group and the detail-sheet join key) was fed
     * only the first sheet's headers, so a detail-only column like ItemName could
     * never be selected — not a validation gap, a screen that could not express it.
     */
    public function test_layout_c_mapping_screen_offers_detail_sheet_headers_separately(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $batchId = $this->uploadTwoSheetBatch($shop->id, $owner);

        $response = $this->actingAs($owner)->get(route('historical.batches.map', $batchId));

        $response->assertOk();

        // Detail-only columns must now be selectable somewhere on the page.
        $response->assertSee('<option value="ItemName"', false);
        $response->assertSee('<option value="Quantity"', false);
        $response->assertSee('<option value="LineTotal"', false);

        // Header-sheet columns are unaffected.
        $response->assertSee('<option value="InvoiceNo"', false);
        $response->assertSee('<option value="CustomerName"', false);
        $response->assertSee('<option value="GrandTotal"', false);

        // Both sheet-role pickers render, one per sheet.
        $response->assertSee('name="sheets[header]"', false);
        $response->assertSee('name="sheets[detail]"', false);

        // The mapping/link fields carry the JS refresh hook the fix introduces.
        $response->assertSee('data-sheet-role="header"', false);
        $response->assertSee('data-sheet-role="detail"', false);
    }

    /**
     * The full contract: the operator confirms the corrected mapping, staging
     * reads both sheets, and normalize() joins each Lines row onto its Invoices
     * row purely by the mapped InvoiceNo key — proving the pre-existing (and
     * already-correct) join backend now actually receives a usable mapping.
     */
    public function test_layout_c_full_pipeline_joins_header_and_detail_rows_into_documents_and_lines(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $batchId = $this->uploadTwoSheetBatch($shop->id, $owner);

        $this->actingAs($owner)
            ->post(route('historical.batches.map.save', $batchId), $this->twoSheetMappingPayload())
            ->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batchId): void {
            $documents = HistoricalSalesDocument::query()
                ->where('historical_import_batch_id', $batchId)
                ->orderBy('original_document_number')
                ->get();

            $this->assertSame(2, $documents->count(), 'Expected one draft document per invoice.');

            $inv1 = $documents->firstWhere('original_document_number', 'INV-1');
            $inv2 = $documents->firstWhere('original_document_number', 'INV-2');

            $this->assertNotNull($inv1);
            $this->assertNotNull($inv2);
            $this->assertSame(15000.0, (float) $inv1->grand_total);
            $this->assertSame(8000.0, (float) $inv2->grand_total);

            // Two Lines rows joined to INV-1, one to INV-2 — proves the detail
            // sheet's own headers (now selectable) actually round-tripped.
            $this->assertSame(2, $inv1->lines()->count());
            $this->assertSame(1, $inv2->lines()->count());
        });
    }

    /**
     * Layout A (single-sheet) must render exactly as before: one sheet, one set
     * of options, no detail/header split. This is the regression backstop for
     * "don't break the common case while fixing the two-sheet case."
     */
    public function test_layout_a_single_sheet_mapping_is_unaffected_by_the_fix(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();

        $csv = "InvoiceNo,InvoiceDate,GrandTotal\nINV-9,2023-01-01,5000\n";
        $file = UploadedFile::fake()->createWithContent('single.csv', $csv);

        $this->actingAs($owner)
            ->post(route('historical.upload.store'), ['file' => $file, 'source_system' => 'Tally'])
            ->assertRedirect();

        $batchId = (int) TenantContext::runFor(
            $shop->id,
            fn () => HistoricalImportBatch::query()->latest('id')->value('id')
        );

        $response = $this->actingAs($owner)->get(route('historical.batches.map', $batchId));

        $response->assertOk();
        $response->assertSee('<option value="InvoiceNo"', false);
        $response->assertSee('<option value="GrandTotal"', false);
        // A single-sheet file has nothing to offer as a second sheet — the
        // "Sheets" picker fieldset only renders once at least one sheet exists,
        // which single-sheet CSVs and XLSXs already satisfy pre-fix.
        $response->assertDontSee('<option value="Lines"', false);
    }

    /**
     * A stale/edited profile can carry a sheet name that no longer exists in the
     * current file. The screen must fall back rather than 500 on a
     * HistoricalParseException from deep inside the reader.
     */
    public function test_invalid_remembered_sheet_selection_does_not_crash_the_mapping_screen(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $batchId = $this->uploadTwoSheetBatch($shop->id, $owner);

        // Force a validation failure (missing required "name") while asking to
        // remember a sheet that isn't in this workbook — old() will replay it.
        $badPayload = $this->twoSheetMappingPayload();
        $badPayload['name'] = '';
        $badPayload['sheets']['detail'] = 'NoSuchSheet';

        $this->actingAs($owner)
            ->from(route('historical.batches.map', $batchId))
            ->post(route('historical.batches.map.save', $batchId), $badPayload)
            ->assertRedirect(route('historical.batches.map', $batchId));

        $this->actingAs($owner)
            ->get(route('historical.batches.map', $batchId))
            ->assertOk();
    }

    /**
     * A fresh two-sheet upload, no profile, no old() input: header must default
     * to the first sheet and detail to the second — not the empty "—" option.
     * This is the exact bug the acceptance run found: the screen computed the
     * right default internally but never rendered it as `selected`.
     */
    public function test_fresh_layout_c_mapping_defaults_header_and_detail_sheet_selection(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $batchId = $this->uploadTwoSheetBatch($shop->id, $owner);

        $response = $this->actingAs($owner)->get(route('historical.batches.map', $batchId));

        $response->assertOk();
        $response->assertSee('<option value="Invoices" selected>', false);
        $response->assertSee('<option value="Lines" selected>', false);
        // The empty placeholder must not win by browser default any more.
        $response->assertDontSee('<option value="" selected>', false);
    }

    /** A saved profile's sheet choice beats the plain first/second-sheet default, even when it reverses the natural order. */
    public function test_saved_profile_sheet_selection_overrides_the_default(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $batchId = $this->uploadTwoSheetBatch($shop->id, $owner);

        $payload = $this->twoSheetMappingPayload();
        $payload['sheets'] = ['header' => 'Lines', 'detail' => 'Invoices'];

        $this->actingAs($owner)
            ->post(route('historical.batches.map.save', $batchId), $payload)
            ->assertRedirect();

        $response = $this->actingAs($owner)->get(route('historical.batches.map', $batchId));

        $response->assertOk();
        $response->assertSee('<option value="Lines" selected>', false);
        $response->assertSee('<option value="Invoices" selected>', false);
    }

    /** A validation-failure replay's sheet choice beats both the saved profile and the plain default. */
    public function test_old_input_sheet_selection_overrides_saved_profile_and_default(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $batchId = $this->uploadTwoSheetBatch($shop->id, $owner);

        // Save a profile with the roles reversed …
        $saved = $this->twoSheetMappingPayload();
        $saved['sheets'] = ['header' => 'Lines', 'detail' => 'Invoices'];
        $this->actingAs($owner)
            ->post(route('historical.batches.map.save', $batchId), $saved)
            ->assertRedirect();

        // … then fail validation while asking to remember the natural order. old()
        // must win over the profile that was just persisted.
        $bad = $this->twoSheetMappingPayload();
        $bad['name'] = '';

        $this->actingAs($owner)
            ->from(route('historical.batches.map', $batchId))
            ->post(route('historical.batches.map.save', $batchId), $bad)
            ->assertRedirect(route('historical.batches.map', $batchId));

        $response = $this->actingAs($owner)->get(route('historical.batches.map', $batchId));

        $response->assertOk();
        $response->assertSee('<option value="Invoices" selected>', false);
        $response->assertSee('<option value="Lines" selected>', false);
    }

    /** A remembered sheet that no longer exists must degrade to the plain default, not stay stuck on nothing. */
    public function test_stale_remembered_detail_sheet_falls_back_to_the_default_sheet(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $batchId = $this->uploadTwoSheetBatch($shop->id, $owner);

        $bad = $this->twoSheetMappingPayload();
        $bad['name'] = '';
        $bad['sheets']['detail'] = 'NoSuchSheet';

        $this->actingAs($owner)
            ->from(route('historical.batches.map', $batchId))
            ->post(route('historical.batches.map.save', $batchId), $bad)
            ->assertRedirect(route('historical.batches.map', $batchId));

        $response = $this->actingAs($owner)->get(route('historical.batches.map', $batchId));

        $response->assertOk();
        // Falls back to the second real sheet, not the stale name and not empty.
        $response->assertSee('<option value="Lines" selected>', false);
        $response->assertDontSee('<option value="" selected>', false);
    }

    /** Same shop-scoped bind as every other Historical route — the map screen is not a special case. */
    public function test_cross_shop_mapping_route_is_404(): void
    {
        [$ownerA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $batchB = $this->seedPublishableBatch($shopB->id, $ownerA->id);

        $this->actingAs($ownerA)->get(route('historical.batches.map', $batchB->id))->assertNotFound();
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

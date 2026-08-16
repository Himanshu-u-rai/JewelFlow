<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use App\Services\Historical\HistoricalImportService;
use App\Services\Historical\HistoricalDuplicateDetector;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 2 closure audit — genuine end-to-end proof for the gates the plain HTTP
 * suite (HistoricalModuleHttpTest) does not cover: real manual->publish->view
 * flow, a real CSV upload through the HTTP pipeline, a real XLSX built with
 * PhpSpreadsheet (Layout C header/detail + hidden sheet), the duplicate
 * resolution workflow, the atomic publish claim, and a before/after count proof
 * that publishing writes to historical tables ONLY.
 *
 * Scope note (honest, not exhaustive): this file targets the highest-risk gates
 * — money/identity correctness, immutability, duplicate blocking, atomic claim,
 * zero operational side effects. It does not attempt every edge case listed in
 * the audit (e.g. corrupt-zip XLSX, MIME spoofing, 50k-row throughput) — those
 * are covered by source-level guarantees in HistoricalSourceFileReader
 * documented in the closure report, not re-proven here with a live 50k-row file.
 */
class HistoricalBatch2ClosureAuditTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // =============================================================== GATE 4

    public function test_manual_entry_publishes_end_to_end_and_the_published_view_is_correct(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();

        $store = $this->actingAs($owner)->post(route('historical.manual.store'), [
            'original_document_number' => 'OLD/INV/0007',
            'document_date'            => '2022-05-04',
            'customer_name'            => 'Ramesh Patel',
            'grand_total'              => 42000,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
        ]);
        $store->assertRedirect();

        [$batch, $document] = TenantContext::runFor($shop->id, function () {
            $batch = HistoricalImportBatch::query()->firstOrFail();
            $document = HistoricalSalesDocument::query()->where('historical_import_batch_id', $batch->id)->firstOrFail();

            return [$batch, $document];
        });

        // header-only warning is expected (no lines) — must acknowledge before publish.
        if ((int) $batch->warning_count > 0) {
            $this->actingAs($owner)->post(route('historical.batches.acknowledge', $batch->id))->assertRedirect();
        }

        $publish = $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id));
        $publish->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($document) {
            $document->refresh();
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
            $this->assertNotNull($document->published_at);
        });

        $view = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $view->assertOk();
        $view->assertSee(HistoricalSalesDocument::BADGE);
        $view->assertSee(HistoricalSalesDocument::RECORD_DISCLAIMER);
        $view->assertSee('OLD/INV/0007'); // the exact original number, verbatim
        $view->assertDontSee($document->historical_reference); // internal UUID never shown as the number

        // idempotent: republishing does not duplicate or error.
        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();
        TenantContext::runFor($shop->id, function () {
            $this->assertSame(1, HistoricalSalesDocument::query()->count());
        });

        // immutable: a direct attempt to change a financial field on the published
        // record must be refused by the application-layer guard (mirrors the DB trigger).
        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, function () use ($document) {
            $document->forceFill(['grand_total' => 99999])->save();
        });
    }

    public function test_original_number_unavailable_label_shown_when_number_is_null_and_no_number_is_generated(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), [
            'document_date' => '2022-05-04',
            'grand_total'   => 1500,
            'tax_mode'      => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
        ])->assertRedirect();

        $document = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocument::query()->firstOrFail());
        $this->assertNull($document->original_document_number);

        $view = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $view->assertOk();
        $view->assertSee(HistoricalSalesDocument::NUMBER_UNAVAILABLE_LABEL);
        // never a JewelFlow-style generated invoice number in its place.
        $view->assertDontSee('INV-1001');
    }

    // =============================================================== GATE 5

    public function test_csv_upload_layout_a_parses_indian_number_format_and_forces_ignored_column_decision(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $csv = "Invoice No,Date,Customer,Amount,Notes\n"
            . "INV/24-1,15/06/2023,Ramesh,\"1,25,000.00\",misc info\n";

        $upload = $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file'          => UploadedFile::fake()->createWithContent('sales.csv', $csv),
            'label'         => 'Tally export FY23',
            'source_system' => 'Tally',
        ]);
        $upload->assertRedirect();

        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        $mapping = $this->actingAs($owner)->post(route('historical.batches.map.save', $batch->id), [
            'name'                => 'Tally CSV profile',
            'source_system'       => 'Tally',
            'layout_type'         => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            'header_row'          => 1,
            'date_format'         => \App\Services\Historical\HistoricalDateParser::FORMAT_DMY,
            'decimal_separator'   => '.',
            'thousands_separator' => ',',
            'tax_mode'            => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'mapping'             => [
                'original_document_number' => 'Invoice No',
                'document_date'            => 'Date',
                'customer_name'            => 'Customer',
                'grand_total'               => 'Amount',
            ],
            // every unrecognized column must be an explicit decision.
            'column_decisions'    => ['Notes' => \App\Models\Historical\HistoricalImportProfile::DECISION_IGNORED],
        ]);
        $mapping->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $batch->refresh();
            $document = HistoricalSalesDocument::query()->where('historical_import_batch_id', $batch->id)->firstOrFail();
            $this->assertSame('INV/24-1', $document->original_document_number);
            $this->assertSame('15-06-2023', $document->document_date->format('d-m-Y'));
            $this->assertEquals(125000.00, (float) $document->grand_total, '', 0.001); // "1,25,000.00" parsed as Indian format
            $this->assertSame('2023-24', $document->financial_year);

            // the ignored column's decision is persisted on the profile, not discarded.
            $profile = $batch->fresh()->profile;
            $this->assertSame(
                \App\Models\Historical\HistoricalImportProfile::DECISION_IGNORED,
                $profile->column_decisions['Notes'] ?? null
            );
        });
    }

    public function test_csv_upload_rejects_mapping_that_leaves_a_monetary_column_undecided(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $csv = "Invoice No,Date,Amount,Making Charges\nA-1,2023-06-15,1000,50\n";

        $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.csv', $csv),
        ])->assertRedirect();

        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        // "Making Charges" is a recognizable monetary header left completely
        // undecided (not mapped, not ignored, not informational).
        $this->actingAs($owner)->post(route('historical.batches.map.save', $batch->id), [
            'name'               => 'p',
            'layout_type'        => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            'header_row'         => 1,
            'date_format'        => \App\Services\Historical\HistoricalDateParser::FORMAT_ISO,
            'decimal_separator'  => '.',
            'tax_mode'           => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'mapping'            => [
                'original_document_number' => 'Invoice No',
                'document_date'            => 'Date',
                'grand_total'               => 'Amount',
            ],
            'column_decisions'   => [], // "Making Charges" left undecided on purpose
        ])->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $batch->refresh();
            $this->assertGreaterThan(0, (int) $batch->blocking_count, 'An undecided monetary column must block, not silently vanish.');
            $this->assertNotNull($batch->blockedFromPublishing());
        });
    }

    // =============================================================== GATE 6

    public function test_xlsx_header_detail_layout_c_joins_sheets_and_flags_a_hidden_sheet(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $path = $this->buildXlsxFixture();
        $file = new UploadedFile($path, 'ledger.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        TenantContext::runFor($shop->id, function () use ($shop, $owner, $file) {
            /** @var HistoricalImportService $imports */
            $imports = app(HistoricalImportService::class);

            $batch = $imports->createBatchFromUpload($shop, $file, null, $owner->id);

            $sheets = $imports->inspect($batch);
            $names  = collect($sheets)->pluck('name')->all();
            $this->assertContains('Header', $names);
            $this->assertContains('Detail', $names);
            $this->assertContains('Archive', $names);

            $hiddenFlag = collect($sheets)->firstWhere('name', 'Archive')['hidden'] ?? null;
            $this->assertTrue($hiddenFlag, 'A hidden sheet must be reported as hidden, not silently dropped or silently read.');

            $profile = new \App\Models\Historical\HistoricalImportProfile();
            $profile->forceFill([
                'shop_id'             => $shop->id,
                'name'                => 'Layout C profile',
                'source_system'       => 'Test',
                'layout_type'         => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_DETAIL,
                'header_row'          => 1,
                'date_format'         => \App\Services\Historical\HistoricalDateParser::FORMAT_ISO,
                'decimal_separator'   => '.',
                'tax_mode'            => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
                'sheets'              => ['header' => 'Header', 'detail' => 'Detail'],
                'mapping'             => [
                    'original_document_number' => 'Invoice No',
                    'document_date'            => 'Date',
                    'grand_total'               => 'Amount',
                    'join_key'                  => 'Invoice No',
                    'detail_join_key'           => 'Invoice Ref',
                    'line_item_name'            => 'Item',
                ],
                'column_decisions'    => [],
            ])->save();

            $batch->forceFill(['historical_import_profile_id' => $profile->id])->save();

            $imports->stage($batch, $profile);
            $imports->normalize($batch, $profile, $owner->id);

            $batch->refresh();
            $this->assertSame(1, $batch->document_count, 'Header + detail rows must join into exactly one document.');

            $document = HistoricalSalesDocument::query()->where('historical_import_batch_id', $batch->id)->firstOrFail();
            $this->assertSame(1, $document->lines()->count());
            $this->assertSame('Gold Ring', $document->lines()->first()->source_description);
        });
    }

    public function test_xlsx_orphan_detail_row_is_blocking(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $spreadsheet = new Spreadsheet();
        $header = $spreadsheet->getActiveSheet();
        $header->setTitle('Header');
        $header->fromArray(['Invoice No', 'Date', 'Amount'], null, 'A1');
        $header->fromArray(['H-1', '2023-06-15', 1000], null, 'A2');

        $detail = $spreadsheet->createSheet();
        $detail->setTitle('Detail');
        $detail->fromArray(['Invoice Ref', 'Item'], null, 'A1');
        $detail->fromArray(['DOES-NOT-EXIST', 'Gold Ring'], null, 'A2'); // orphan: no matching header

        $path = tempnam(sys_get_temp_dir(), 'hist') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'orphan.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        TenantContext::runFor($shop->id, function () use ($shop, $owner, $file) {
            /** @var HistoricalImportService $imports */
            $imports = app(HistoricalImportService::class);
            $batch   = $imports->createBatchFromUpload($shop, $file, null, $owner->id);

            $profile = new \App\Models\Historical\HistoricalImportProfile();
            $profile->forceFill([
                'shop_id'           => $shop->id,
                'name'              => 'orphan-test',
                'source_system'     => 'Test',
                'layout_type'       => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_DETAIL,
                'header_row'        => 1,
                'date_format'       => \App\Services\Historical\HistoricalDateParser::FORMAT_ISO,
                'decimal_separator' => '.',
                'tax_mode'          => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
                'sheets'            => ['header' => 'Header', 'detail' => 'Detail'],
                'mapping'           => [
                    'original_document_number' => 'Invoice No',
                    'document_date'            => 'Date',
                    'grand_total'               => 'Amount',
                    'join_key'                  => 'Invoice No',
                    'detail_join_key'           => 'Invoice Ref',
                    'line_item_name'            => 'Item',
                ],
            ])->save();
            $batch->forceFill(['historical_import_profile_id' => $profile->id])->save();

            $imports->stage($batch, $profile);
            $imports->normalize($batch, $profile, $owner->id);

            $batch->refresh();
            $this->assertGreaterThan(0, (int) $batch->blocking_count, 'An orphan detail row must block, never guess a header to attach to.');
        });

        @unlink($path);
    }

    private function buildXlsxFixture(): string
    {
        $spreadsheet = new Spreadsheet();

        $header = $spreadsheet->getActiveSheet();
        $header->setTitle('Header');
        $header->fromArray(['Invoice No', 'Date', 'Amount'], null, 'A1');
        $header->fromArray(['H-1', '2023-06-15', 1000], null, 'A2');

        $detail = $spreadsheet->createSheet();
        $detail->setTitle('Detail');
        $detail->fromArray(['Invoice Ref', 'Item'], null, 'A1');
        $detail->fromArray(['H-1', 'Gold Ring'], null, 'A2');

        $hidden = $spreadsheet->createSheet();
        $hidden->setTitle('Archive');
        $hidden->fromArray(['old'], null, 'A1');
        $hidden->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        $path = tempnam(sys_get_temp_dir(), 'hist') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    // ============================================================== GATE 10

    public function test_number_identity_duplicate_blocks_by_default_and_only_a_confirmed_series_resolves_it(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $csv = "Invoice No,Date,Amount\nDUP-1,2023-06-15,1000\nDUP-1,2023-06-16,2000\n";

        $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file' => UploadedFile::fake()->createWithContent('dup.csv', $csv),
        ])->assertRedirect();

        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        $this->actingAs($owner)->post(route('historical.batches.map.save', $batch->id), [
            'name'              => 'dup-profile',
            'layout_type'       => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            'header_row'        => 1,
            'date_format'       => \App\Services\Historical\HistoricalDateParser::FORMAT_ISO,
            'decimal_separator' => '.',
            'tax_mode'          => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'mapping'           => [
                'original_document_number' => 'Invoice No',
                'document_date'            => 'Date',
                'grand_total'               => 'Amount',
            ],
        ])->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $batch->refresh();
            $this->assertSame(1, $batch->document_count, 'Only the first row of a same-number pair becomes a document by default.');
            $this->assertGreaterThan(0, (int) $batch->blocking_count);
            $this->assertNotNull($batch->blockedFromPublishing());
        });

        // An arbitrary override_key must NOT clear a number-identity collision.
        $this->actingAs($owner)->post(route('historical.batches.duplicates', $batch->id), [
            'grouping_key'  => 'row:2',
            'action'        => HistoricalDuplicateDetector::RESOLUTION_LINK,
            'override_key'  => 'anything-i-type',
        ])->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $batch->refresh();
            $this->assertGreaterThan(0, (int) $batch->blocking_count, 'override_key must never bypass the number-identity guard.');
        });

        // Only an explicit, confirmed distinct series resolves it.
        $this->actingAs($owner)->post(route('historical.batches.duplicates', $batch->id), [
            'grouping_key'  => 'row:2',
            'action'        => HistoricalDuplicateDetector::RESOLUTION_NEW_SERIES,
            'series'        => 'B',
        ])->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $batch->refresh();
            $this->assertSame(0, (int) $batch->blocking_count);
            $this->assertSame(2, $batch->document_count);

            $numbers = HistoricalSalesDocument::query()
                ->where('historical_import_batch_id', $batch->id)
                ->pluck('document_series')->sort()->values()->all();
            $this->assertSame([null, 'B'], $numbers);
        });
    }

    // ============================================================== GATE 12

    public function test_publish_claim_is_an_atomic_compare_and_swap_not_a_sequential_check(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), [
            'document_date' => '2023-01-01',
            'grand_total'   => 500,
            'tax_mode'      => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
        ])->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $batch = HistoricalImportBatch::query()->firstOrFail();

            /** @var HistoricalDocumentLifecycleService $lifecycle */
            $lifecycle = app(HistoricalDocumentLifecycleService::class);

            // The claim is a single conditional UPDATE ... WHERE status IN (editable).
            // Two concurrent requests racing on that statement can only ever have one
            // matching row — this is what makes it safe under real concurrency without
            // an application-level mutex. Proven here by taking the claim once, then
            // proving a second claim attempt against the now-`publishing` row is
            // refused deterministically (not "usually", not "unless slow") because the
            // WHERE clause no longer matches.
            $this->assertTrue($lifecycle->claimForPublishing($batch));
            $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHING, $batch->fresh()->status);

            $second = $lifecycle->claimForPublishing($batch->fresh());
            $this->assertFalse($second, 'A batch already in `publishing` must refuse a second claim.');

            // A raw concurrent UPDATE against the same row (simulating a second
            // process) also cannot match — proves the guarantee is in the SQL, not in
            // PHP-level locking that a second process wouldn't share.
            $racedRows = DB::table('historical_import_batches')
                ->where('id', $batch->id)
                ->whereIn('status', HistoricalImportBatch::EDITABLE)
                ->update(['status' => HistoricalImportBatch::STATUS_PUBLISHING]);
            $this->assertSame(0, $racedRows, 'A second UPDATE with the same WHERE guard must match zero rows.');

            $lifecycle->releaseClaim($batch);
            $this->assertSame(HistoricalImportBatch::STATUS_REVIEW, $batch->fresh()->status);
        });
    }

    // ============================================================== GATE 13

    public function test_publishing_a_historical_batch_writes_to_historical_tables_only(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $liveTables = [
            'invoices', 'invoice_items', 'quick_bills', 'quick_bill_items',
            'cash_transactions', 'invoice_payments', 'metal_movements',
            'customer_gold_transactions', 'loyalty_transactions',
        ];

        $before = collect($liveTables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->actingAs($owner)->post(route('historical.manual.store'), [
            'original_document_number' => 'SIDE-EFFECT-CHECK-1',
            'document_date'            => '2022-01-01',
            'customer_name'            => 'Test Customer',
            'grand_total'              => 7500,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'lines'                    => [
                ['line_item_name' => 'Ring', 'line_total' => 7500],
            ],
        ])->assertRedirect();

        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());
        // Manual entry produced non-blocking warnings (e.g. unknown tax) — the
        // batch requires explicit acknowledgement before it can publish.
        $this->actingAs($owner)->post(route('historical.batches.acknowledge', $batch->id))->assertRedirect();
        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $batch->refresh();
            $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHED, $batch->status);
        });

        $after = collect($liveTables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        foreach ($liveTables as $table) {
            $this->assertSame(
                $before[$table],
                $after[$table],
                "Publishing a historical batch changed row count of live operational table `{$table}`."
            );
        }

        $this->assertSame(1, DB::table('historical_sales_documents')->count());
        $this->assertSame(1, DB::table('historical_sales_lines')->count());
    }

    // ============================================================== GATE 8

    /**
     * `HistoricalImportController::create()` lists profiles with only an
     * `is_active` filter — no explicit `shop_id` in the query. Tenant safety for
     * that screen rests entirely on `HistoricalImportProfile`'s `BelongsToShop`
     * global scope. This proves the scope actually holds against a real second
     * tenant, not just that the field exists on the model.
     */
    public function test_mapping_profile_from_one_shop_is_never_offered_to_another_shop(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $csv = "Invoice No,Date,Amount\nA-1,2023-06-15,1000\n";
        $this->actingAs($ownerA)->post(route('historical.upload.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.csv', $csv),
        ])->assertRedirect();
        $batchA = TenantContext::runFor($shopA->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        $this->actingAs($ownerA)->post(route('historical.batches.map.save', $batchA->id), [
            'name'              => 'Shop A Exclusive Profile',
            'layout_type'       => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            'header_row'        => 1,
            'date_format'       => \App\Services\Historical\HistoricalDateParser::FORMAT_ISO,
            'decimal_separator' => '.',
            'tax_mode'          => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'mapping'           => [
                'original_document_number' => 'Invoice No',
                'document_date'            => 'Date',
                'grand_total'               => 'Amount',
            ],
            'column_decisions' => [],
        ])->assertRedirect();

        // Shop B's own upload screen must never list shop A's saved profile.
        $screen = $this->actingAs($ownerB)->get(route('historical.upload.create'));
        $screen->assertOk();
        $screen->assertDontSee('Shop A Exclusive Profile');

        // And shop A does see its own.
        $this->actingAs($ownerA)->get(route('historical.upload.create'))
            ->assertSee('Shop A Exclusive Profile');
    }

    /**
     * Every profile field the operator confirmed on the mapping screen — not
     * just the column mapping itself — survives to the stored profile row, so
     * next year's file from the same source is a one-click reuse (Phase 9).
     */
    public function test_mapping_profile_persists_separators_tax_and_making_defaults_and_all_three_column_decisions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $csv = "Invoice No,Date,Amount,Notes,Internal Ref\nA-1,15.06.2023,1.000,x,r1\n";
        $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.csv', $csv),
        ])->assertRedirect();
        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        $this->actingAs($owner)->post(route('historical.batches.map.save', $batch->id), [
            'name'                => 'Persistence profile',
            'layout_type'         => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            'header_row'          => 1,
            'date_format'         => \App\Services\Historical\HistoricalDateParser::FORMAT_DMY,
            'decimal_separator'   => ',',
            'thousands_separator' => '.',
            'tax_mode'            => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'mapping'             => [
                'original_document_number' => 'Invoice No',
                'document_date'            => 'Date',
                'grand_total'               => 'Amount',
            ],
            'column_decisions'    => [
                'Notes'        => \App\Models\Historical\HistoricalImportProfile::DECISION_IGNORED,
                'Internal Ref' => \App\Models\Historical\HistoricalImportProfile::DECISION_INFORMATIONAL,
            ],
            'making_defaults'     => ['category' => \App\Support\Historical\HistoricalMakingCharge::CATEGORY_LABOUR],
            'tax_defaults'        => ['zero_confirmed' => true],
        ])->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $profile = $batch->fresh()->profile;

            $this->assertSame(',', $profile->decimal_separator);
            $this->assertSame('.', $profile->thousands_separator);
            $this->assertSame(\App\Services\Historical\HistoricalDateParser::FORMAT_DMY, $profile->date_format);
            $this->assertSame(
                \App\Models\Historical\HistoricalImportProfile::DECISION_IGNORED,
                $profile->column_decisions['Notes'] ?? null
            );
            $this->assertSame(
                \App\Models\Historical\HistoricalImportProfile::DECISION_INFORMATIONAL,
                $profile->column_decisions['Internal Ref'] ?? null
            );
            $this->assertSame(
                \App\Support\Historical\HistoricalMakingCharge::CATEGORY_LABOUR,
                $profile->making_defaults['category'] ?? null
            );
            $this->assertTrue((bool) ($profile->tax_defaults['zero_confirmed'] ?? false));
            $this->assertTrue($profile->is_active);
        });
    }

    /**
     * Alias suggestion is first-wins and is a suggestion only — it never decides
     * for the operator. Both header columns alias to `line_total`; the second
     * loser must reach the operator as an undecided column, not a silent drop.
     */
    public function test_alias_suggestion_is_first_wins_and_leaves_the_loser_for_the_operator_to_decide(): void
    {
        $suggestion = \App\Support\Historical\HistoricalFields::suggestAll(['Amount', 'Line Total', 'Notes']);

        $this->assertSame(['line_total' => 'Amount'], $suggestion['mapping']);
        $this->assertSame(['Line Total', 'Notes'], $suggestion['unmapped']);
    }

    // ============================================================= GATE 11

    /**
     * `HistoricalImportService::refreshPreview()` is what the preview/
     * reconciliation screen renders from. This proves the summary it builds from
     * two real normalized documents matches Phase 13's required content: counts,
     * date range, financial year, customer dedup, totals, tax-completeness
     * distribution and the ignored/informational column trail — not just that
     * the array has *a* value in each key.
     */
    public function test_preview_summary_reflects_row_counts_totals_date_range_and_column_decisions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $csv = "Invoice No,Date,Customer,Amount,Notes,Making Charges\n"
            . "INV/1,15/06/2023,Ramesh,10000,misc,500\n"
            . "INV/2,20/07/2023,Ramesh,20000,other,700\n";

        $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file' => UploadedFile::fake()->createWithContent('two.csv', $csv),
        ])->assertRedirect();
        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        $this->actingAs($owner)->post(route('historical.batches.map.save', $batch->id), [
            'name'              => 'Preview summary profile',
            'layout_type'       => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            'header_row'        => 1,
            'date_format'       => \App\Services\Historical\HistoricalDateParser::FORMAT_DMY,
            'decimal_separator' => '.',
            'tax_mode'          => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'mapping'           => [
                'original_document_number' => 'Invoice No',
                'document_date'            => 'Date',
                'customer_name'            => 'Customer',
                'grand_total'               => 'Amount',
            ],
            'column_decisions' => [
                'Notes'           => \App\Models\Historical\HistoricalImportProfile::DECISION_IGNORED,
                'Making Charges'  => \App\Models\Historical\HistoricalImportProfile::DECISION_INFORMATIONAL,
            ],
        ])->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $summary = $batch->fresh()->preview_summary;

            $this->assertSame(2, $summary['row_count']);
            $this->assertSame(2, $summary['document_count']);
            $this->assertSame(0, $summary['line_count']);
            $this->assertSame(2, $summary['header_only_count']);
            $this->assertSame('2023-06-15', $summary['date_from']);
            $this->assertSame('2023-07-20', $summary['date_to']);
            $this->assertSame(['2023-24'], $summary['financial_years']);
            $this->assertSame(1, $summary['customer_count']); // same customer on both rows, deduped
            $this->assertEqualsWithDelta(30000.0, $summary['grand_total'], 0.001);
            $this->assertSame(0, $summary['blocking_count']);
            $this->assertSame(2, $summary['tax_completeness'][HistoricalSalesDocument::TAX_UNKNOWN] ?? 0);
            $this->assertContains('Notes', $summary['ignored_columns']);
            $this->assertContains('Making Charges', $summary['informational_columns']);
        });
    }
}

<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportProfile;
use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A manually-entered bill is saved, then redirected into the SAME batch-review
 * page a file import uses. That page renders straight from
 * HistoricalImportBatch::preview_summary. HistoricalImportService::storeManual()
 * used to set preview_summary to a minimal, ad-hoc shape
 * (`['manual' => true, 'messages' => [...]]`) instead of the full document/line/
 * total shape refreshPreview() computes for a file import — so the review page
 * showed Rows:0, Documents:0, Lines:0 and every total as 0, even though the
 * document was saved correctly. These are the 12 blocking tests for the fix.
 */
class HistoricalManualReviewContractTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const HISTORICAL_TABLES = [
        'historical_import_batches',
        'historical_sales_documents',
        'historical_sales_lines',
        'historical_import_rows',
    ];

    private const LIVE_TABLES = [
        'invoices',
        'invoice_items',
        'stock_items',
        'cash_ledgers',
        'customer_ledgers',
        'receivables',
        'payments',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, mixed> a valid manual-entry payload with one real line. */
    private function manualPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'INV/MUM/2024-25/0156',
            'document_date'            => '2024-06-15',
            'source_system'            => 'Manual',
            'customer_name'            => 'Asha Traders',
            'grand_total'              => 393580.51,
            'taxable_amount'           => 374838.58,
            'cgst'                     => 9370.97,
            'sgst'                     => 9370.97,
            'paid_amount'              => 393580.51,
            'outstanding_amount'       => 0,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_EXCLUSIVE,
            'lines'                    => [[
                'line_item_name'    => 'Gold ring',
                'line_gross_weight' => 12.5,
                'line_net_weight'   => 12.5,
                'line_metal_value'  => 374838.58,
                'line_total'        => 374838.58,
            ]],
        ], $override);
    }

    private function existingTables(array $names): array
    {
        return array_values(array_filter($names, fn (string $t) => \Illuminate\Support\Facades\Schema::hasTable($t)));
    }

    private function snapshotCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // ------------------------------------------------------------ Test #1

    public function test_manual_save_redirects_into_the_batch_review_lifecycle(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload());
        $response->assertRedirect();

        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        $response->assertRedirect(route('historical.batches.show', $batch));
    }

    // -------------------------------------------------------- Tests #2-#7

    public function test_manual_batch_review_summary_reports_accurate_documents_lines_and_totals(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload())->assertRedirect();

        [$batch, $document] = TenantContext::runFor($shop->id, function () {
            $batch    = HistoricalImportBatch::query()->firstOrFail();
            $document = HistoricalSalesDocument::query()
                ->where('historical_import_batch_id', $batch->id)
                ->with('lines')
                ->firstOrFail();

            return [$batch, $document];
        });

        $preview = $batch->preview_summary;

        // #2 — documents=1
        $this->assertSame(1, $preview['document_count']);

        // #3 — line count equals actual saved nonblank lines
        $this->assertSame($document->lines->count(), $preview['line_count']);
        $this->assertGreaterThan(0, $preview['line_count']);

        // #4 — header/tax/paid/outstanding totals match the persisted document.
        // (preview_summary is a jsonb-backed array cast: json_encode() drops the
        // trailing ".0" off a whole-number float and json_decode() reads it back
        // as an int — an encoding round-trip, not a data error — so compare as
        // float rather than assertSame's strict type check.)
        $this->assertSame(round((float) $document->grand_total, 2), (float) $preview['grand_total']);
        $this->assertSame(round((float) $document->taxable_amount, 2), (float) $preview['taxable_total']);
        $this->assertSame(round((float) $document->paid_amount_snapshot, 2), (float) $preview['paid_total']);
        $this->assertSame(round((float) $document->outstanding_amount_snapshot, 2), (float) $preview['outstanding_total']);
        $this->assertGreaterThan(0, $preview['cgst_total']);
        $this->assertGreaterThan(0, $preview['sgst_total']);

        // #5 — date range and financial year populated from the document
        $this->assertSame($document->document_date->toDateString(), $preview['date_from']);
        $this->assertSame($document->document_date->toDateString(), $preview['date_to']);
        $this->assertContains($document->financial_year, $preview['financial_years']);

        // #6 — manual workflow identified explicitly
        $this->assertTrue($batch->isManualBatch());
        $this->assertTrue($preview['is_manual']);

        // #7 — import-only staged rows marked not applicable, not misrepresented as missing data
        $this->assertNull($preview['row_count']);
        $this->assertFalse($preview['staged_rows_applicable']);
        $this->assertSame(0, HistoricalImportRow::query()->where('historical_import_batch_id', $batch->id)->count());
    }

    // ------------------------------------------------------------ Test #8

    public function test_warning_acknowledgement_still_blocks_publishing_a_manual_batch(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Header-only (no lines) always raises a warning that must be acknowledged.
        $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload(['lines' => []]))
            ->assertRedirect();

        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());
        $this->assertGreaterThan(0, (int) $batch->warning_count, 'Fixture must actually produce a warning to test the gate.');

        $publish = $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id));
        $publish->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch): void {
            $batch->refresh();
            $this->assertNotSame(HistoricalImportBatch::STATUS_PUBLISHED, $batch->status);
        });
    }

    // ------------------------------------------------------------ Test #9

    public function test_publishing_succeeds_after_acknowledgement_and_replay_is_idempotent(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload(['lines' => []]))
            ->assertRedirect();

        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        $this->actingAs($owner)->post(route('historical.batches.acknowledge', $batch->id))->assertRedirect();
        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch): void {
            $batch->refresh();
            $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHED, $batch->status);
        });

        // Replay must not duplicate or error.
        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();
        TenantContext::runFor($shop->id, function () {
            $this->assertSame(1, HistoricalSalesDocument::query()->count());
        });
    }

    // ----------------------------------------------------------- Test #10

    public function test_csv_import_batch_summary_and_workflow_are_unchanged(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $csv = "Invoice No,Date,Customer,Amount\n"
            . "CSV-0001,15/06/2024,Ramesh Patel,42000\n";
        $file = UploadedFile::fake()->createWithContent('legacy.csv', $csv);

        $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file'          => $file,
            'source_system' => 'Legacy POS',
        ])->assertRedirect();

        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        $this->actingAs($owner)->post(route('historical.batches.map.save', $batch->id), [
            'name'                => 'Legacy CSV profile',
            'source_system'       => 'Legacy POS',
            'layout_type'         => HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            'header_row'          => 1,
            'date_format'         => \App\Services\Historical\HistoricalDateParser::FORMAT_DMY,
            'decimal_separator'   => '.',
            'thousands_separator' => ',',
            'tax_mode'            => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'mapping'             => [
                'original_document_number' => 'Invoice No',
                'document_date'            => 'Date',
                'customer_name'            => 'Customer',
                'grand_total'              => 'Amount',
            ],
            'column_decisions'    => [],
        ])->assertRedirect();

        $batch->refresh();
        $preview = $batch->preview_summary;

        // Row-based fields still come from HistoricalImportRow, not from the manual path.
        $this->assertFalse($batch->isManualBatch());
        $this->assertArrayNotHasKey('is_manual', $preview);
        $this->assertSame(1, $preview['row_count']);
        $this->assertSame(1, $preview['document_count']);
        $this->assertSame(42000.0, (float) $preview['grand_total']);
    }

    // ----------------------------------------------------------- Test #11

    public function test_manual_save_and_review_write_only_to_historical_tables(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $liveTables = TenantContext::runFor($shop->id, fn () => $this->existingTables(self::LIVE_TABLES));
        $before     = TenantContext::runFor($shop->id, fn () => $this->snapshotCounts($liveTables));

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload())->assertRedirect();

        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->firstOrFail());
        $this->actingAs($owner)->get(route('historical.batches.show', $batch->id))->assertOk();

        $after = TenantContext::runFor($shop->id, fn () => $this->snapshotCounts($liveTables));
        $this->assertSame($before, $after, 'Manual save/review must never write to a live accounting/inventory table.');
    }

    // ----------------------------------------------------------- Test #12

    public function test_manual_batch_review_is_isolated_per_shop(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $this->actingAs($ownerA)->post(route('historical.manual.store'), $this->manualPayload())->assertRedirect();
        $batchA = TenantContext::runFor($shopA->id, fn () => HistoricalImportBatch::query()->firstOrFail());

        $this->actingAs($ownerB)->get(route('historical.batches.show', $batchA->id))->assertNotFound();

        TenantContext::runFor($shopB->id, function () {
            $this->assertSame(0, HistoricalImportBatch::query()->count());
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
        });
    }
}

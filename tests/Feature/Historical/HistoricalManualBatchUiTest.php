<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportProfile;
use App\Models\Historical\HistoricalSalesDocument;
use App\Support\TenantContext;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class HistoricalManualBatchUiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, mixed> */
    private function manualPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'original_document_number' => 'UI-MANUAL-100',
            'document_date' => '2024-06-15',
            'source_system' => 'Manual QA',
            'customer_name' => 'Manual Review Customer',
            'taxable_amount' => 1000,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'outstanding_amount' => 0,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'lines' => [
                [
                    'line_item_name' => 'Archive gold ring',
                    'line_quantity' => 1,
                    'line_net_weight' => 5,
                    'line_total' => 600,
                ],
                [
                    'line_item_name' => 'Archive gold chain',
                    'line_quantity' => 1,
                    'line_net_weight' => 3,
                    'line_total' => 400,
                ],
            ],
        ], $overrides);
    }

    /** @return array{HistoricalImportBatch, HistoricalSalesDocument} */
    private function storeManualReview($owner, int $shopId, array $overrides = []): array
    {
        $this->actingAs($owner)
            ->post(route('historical.manual.store'), $this->manualPayload($overrides))
            ->assertRedirect();

        return TenantContext::runFor($shopId, function (): array {
            $batch = HistoricalImportBatch::query()->latest('id')->firstOrFail();
            $document = HistoricalSalesDocument::query()
                ->where('historical_import_batch_id', $batch->id)
                ->with('lines')
                ->firstOrFail();

            return [$batch, $document];
        });
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function firstNode(DOMXPath $xpath, string $expression): DOMElement
    {
        $nodes = $xpath->query($expression);

        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Expected one rendered node for {$expression}.");
        $this->assertInstanceOf(DOMElement::class, $nodes->item(0));

        return $nodes->item(0);
    }

    /** @return list<string> */
    private function workflowLabels(DOMXPath $xpath): array
    {
        $nodes = $xpath->query("//*[@data-historical-workflow]//*[@data-historical-step-label]");
        $this->assertNotFalse($nodes);

        return array_map(
            fn (DOMElement $node): string => trim($node->textContent),
            iterator_to_array($nodes)
        );
    }

    /**
     * A manually typed bill no longer has a user-facing batch page: its batch is an
     * internal one-document container and its URL forwards to the document.
     *
     * These four manual cases therefore assert the DOCUMENT-page lifecycle contract
     * (the `lifecycle` view data) rather than batch-page markup. That contract is
     * backend-owned and is exactly what the document Blade is built against, so the
     * facts the manual batch summary used to display — findings, acknowledgement,
     * publishability, workflow state — stay covered while the presentation moves.
     */
    public function test_manual_batch_url_forwards_and_the_document_carries_the_review_contract(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch, $document] = $this->storeManualReview($owner, $shop->id);

        $this->actingAs($owner)
            ->get(route('historical.batches.show', $batch))
            ->assertRedirect(route('historical.documents.show', $document->id));

        $response = $this->actingAs($owner)
            ->get(route('historical.documents.show', $document->id))
            ->assertOk();

        $lifecycle = $response->viewData('lifecycle');

        $this->assertTrue($lifecycle['is_manual']);
        $this->assertTrue($lifecycle['is_draft']);
        $this->assertFalse($lifecycle['is_published']);
        $this->assertSame(HistoricalImportBatch::STATUS_REVIEW, $lifecycle['batch_status']);

        // Findings, with the same severities the batch summary used to count.
        $this->assertSame(0, $lifecycle['blocking_count']);
        $this->assertSame([], $lifecycle['blocking']);
        $this->assertGreaterThan(0, $lifecycle['warning_count']);
        $this->assertNotEmpty($lifecycle['warnings']);
        $this->assertNotEmpty($lifecycle['informational']);

        // Unacknowledged warnings hold the publish gate shut, and the reason is
        // stated rather than merely implied by a disabled button.
        $this->assertFalse($lifecycle['warnings_acknowledged']);
        $this->assertFalse($lifecycle['can_publish']);
        $this->assertNotNull($lifecycle['publish_blocker']);

        // The bill itself is on the page it belongs to, with its real lines.
        $this->assertSame('UI-MANUAL-100', $document->original_document_number);
        $this->assertSame('2024-06-15', $document->document_date->toDateString());
        $this->assertSame(2, $document->lines->count());
        $this->assertSame(1000.0, round((float) $document->grand_total, 2));

        // Staged import rows are a file-import concept and must not appear here.
        $this->assertSame(0, $batch->rows()->count());
    }

    public function test_manual_warning_acknowledgement_gate_moves_to_the_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch, $document] = $this->storeManualReview($owner, $shop->id);
        $this->assertGreaterThan(0, (int) $batch->warning_count);

        $before = $this->actingAs($owner)->get(route('historical.documents.show', $document->id))->assertOk();
        $this->assertFalse($before->viewData('lifecycle')['warnings_acknowledged']);
        $this->assertFalse($before->viewData('lifecycle')['can_publish']);

        // Publishing before acknowledging is refused by the same batch-level gate
        // the batch page used — no second, weaker rule for the document route.
        $this->actingAs($owner)
            ->post(route('historical.documents.publish', $document->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        TenantContext::runFor($shop->id, function () use ($document): void {
            $this->assertSame(HistoricalSalesDocument::STATUS_DRAFT, $document->fresh()->status);
        });

        $this->actingAs($owner)
            ->post(route('historical.documents.acknowledge', $document->id))
            ->assertRedirect();

        $after = $this->actingAs($owner)->get(route('historical.documents.show', $document->id))->assertOk();
        $this->assertTrue($after->viewData('lifecycle')['warnings_acknowledged']);
        $this->assertTrue($after->viewData('lifecycle')['can_publish']);
        $this->assertNull($after->viewData('lifecycle')['publish_blocker']);
    }

    public function test_csv_import_keeps_file_workflow_reconciliation_and_staged_rows(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $file = UploadedFile::fake()->createWithContent(
            'source-aware.csv',
            "InvoiceNo,InvoiceDate,GrandTotal\nCSV-UI-1,2024-06-15,42000\n"
        );

        $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file' => $file,
            'source_system' => 'Legacy CSV',
        ])->assertRedirect();
        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->latest('id')->firstOrFail());

        $this->actingAs($owner)->post(route('historical.batches.map.save', $batch), [
            'name' => 'Source-aware CSV profile',
            'source_system' => 'Legacy CSV',
            'layout_type' => HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            'header_row' => 1,
            'date_format' => 'DD/MM/YYYY',
            'decimal_separator' => '.',
            'thousands_separator' => ',',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'mapping' => [
                'original_document_number' => 'InvoiceNo',
                'document_date' => 'InvoiceDate',
                'grand_total' => 'GrandTotal',
            ],
            'column_decisions' => [],
        ])->assertRedirect();

        $response = $this->actingAs($owner)->get(route('historical.batches.show', $batch))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(['Upload', 'Map', 'Review', 'Publish'], $this->workflowLabels($xpath));
        $importSummary = $this->firstNode($xpath, "//*[@data-historical-batch-summary='import']");
        $this->assertStringContainsString('Reconciliation preview', $importSummary->textContent);
        $this->assertStringContainsString('Rows', $importSummary->textContent);
        $this->assertStringContainsString('Header-only', $importSummary->textContent);
        $this->firstNode($xpath, "//*[@data-historical-register='staged-desktop']");
        $this->firstNode($xpath, "//*[@data-historical-register='staged-mobile']");
        $this->assertSame(0, $xpath->query("//*[@data-historical-batch-context='manual']")?->length);
    }

    public function test_layout_c_xlsx_keeps_the_file_import_workflow(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $file = UploadedFile::fake()->createWithContent('source-aware.xlsx', $this->layoutCXlsx());

        $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file' => $file,
            'source_system' => 'Legacy XLSX',
        ])->assertRedirect();
        $batch = TenantContext::runFor($shop->id, fn () => HistoricalImportBatch::query()->latest('id')->firstOrFail());

        $this->actingAs($owner)->post(route('historical.batches.map.save', $batch), [
            'name' => 'Source-aware Layout C profile',
            'source_system' => 'Legacy XLSX',
            'layout_type' => HistoricalImportProfile::LAYOUT_HEADER_DETAIL,
            'header_row' => 1,
            'date_format' => 'YYYY-MM-DD',
            'decimal_separator' => '.',
            'thousands_separator' => ',',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'sheets' => ['header' => 'Invoices', 'detail' => 'Lines'],
            'mapping' => [
                'original_document_number' => 'InvoiceNo',
                'document_date' => 'InvoiceDate',
                'grand_total' => 'GrandTotal',
                'line_item_name' => 'ItemName',
                'line_quantity' => 'Quantity',
                'line_total' => 'LineTotal',
                'join_key' => 'InvoiceNo',
                'detail_join_key' => 'InvoiceNo',
            ],
        ])->assertRedirect();

        $response = $this->actingAs($owner)->get(route('historical.batches.show', $batch))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(['Upload', 'Map', 'Review', 'Publish'], $this->workflowLabels($xpath));
        $this->firstNode($xpath, "//*[@data-historical-batch-summary='import']");
        $this->firstNode($xpath, "//*[@data-historical-register='staged-desktop']");
        $this->assertStringContainsString('Reconciliation preview', $response->getContent());
    }

    public function test_manual_document_lifecycle_actions_remain_permission_gated(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $document] = $this->storeManualReview($owner, $shop->id);
        $this->grantOnlyPermissions($owner, ['historical.view']);

        // Read stays open, every mutation closes — enforced by the route, not by
        // whether the Blade happened to render a button.
        $this->actingAs($owner)->get(route('historical.documents.show', $document->id))->assertOk();
        $this->actingAs($owner)->post(route('historical.documents.acknowledge', $document->id))->assertForbidden();
        $this->actingAs($owner)->post(route('historical.documents.publish', $document->id))->assertForbidden();

        TenantContext::runFor($shop->id, function () use ($document): void {
            $this->assertSame(HistoricalSalesDocument::STATUS_DRAFT, $document->fresh()->status);
        });
    }

    public function test_published_manual_document_is_read_only_and_republishing_is_a_no_op(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch, $document] = $this->storeManualReview($owner, $shop->id);

        if ((int) $batch->warning_count > 0) {
            $this->actingAs($owner)->post(route('historical.documents.acknowledge', $document->id))->assertRedirect();
        }
        $this->actingAs($owner)->post(route('historical.documents.publish', $document->id))->assertRedirect();

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document->id))->assertOk();
        $lifecycle = $response->viewData('lifecycle');

        $this->assertTrue($lifecycle['is_published']);
        $this->assertFalse($lifecycle['is_draft']);
        $this->assertFalse($lifecycle['can_publish']);
        $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHED, $lifecycle['batch_status']);
        $this->assertNotNull($lifecycle['publish_blocker']);

        // Idempotent replay: pressing publish again is a no-op, not an error and
        // not a second document.
        $this->actingAs($owner)
            ->post(route('historical.documents.publish', $document->id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        TenantContext::runFor($shop->id, function () use ($document): void {
            $this->assertSame(1, HistoricalSalesDocument::query()->count());
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->fresh()->status);
        });
    }

    private function layoutCXlsx(): string
    {
        $spreadsheet = new Spreadsheet();
        $invoices = $spreadsheet->getActiveSheet();
        $invoices->setTitle('Invoices');
        $invoices->fromArray(['InvoiceNo', 'InvoiceDate', 'GrandTotal'], null, 'A1');
        $invoices->fromArray(['XLSX-UI-1', '2024-06-15', 15000], null, 'A2');

        $lines = $spreadsheet->createSheet();
        $lines->setTitle('Lines');
        $lines->fromArray(['InvoiceNo', 'ItemName', 'Quantity', 'LineTotal'], null, 'A1');
        $lines->fromArray(['XLSX-UI-1', 'Gold ring', 1, 15000], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'historical_manual_ui_');
        $this->assertNotFalse($path);

        try {
            (new Xlsx($spreadsheet))->save($path);
            $contents = file_get_contents($path);
            $this->assertNotFalse($contents);

            return $contents;
        } finally {
            @unlink($path);
        }
    }
}

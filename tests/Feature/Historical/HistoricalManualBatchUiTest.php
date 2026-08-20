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

    private function metricValue(DOMXPath $xpath, string $metric): string
    {
        return trim($this->firstNode(
            $xpath,
            "//*[@data-historical-batch-summary='manual']//*[@data-historical-metric='{$metric}']//*[@data-historical-metric-value]"
        )->textContent);
    }

    public function test_manual_batch_renders_source_aware_workflow_summary_and_saved_bill(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch, $document] = $this->storeManualReview($owner, $shop->id);

        $response = $this->actingAs($owner)
            ->get(route('historical.batches.show', $batch))
            ->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(['Enter', 'Preview', 'Review', 'Publish'], $this->workflowLabels($xpath));
        $this->firstNode($xpath, "//*[@data-historical-step='review']//*[@aria-current='step']");

        $context = $this->firstNode($xpath, "//*[@data-historical-batch-context='manual']");
        $this->assertStringContainsString('Historical bill review', $context->textContent);
        $this->assertStringContainsString(
            'Review the saved historical draft and its findings before publishing immutable evidence.',
            $context->textContent
        );

        $this->firstNode($xpath, "//*[@data-historical-batch-summary='manual']");
        $this->assertSame('1', $this->metricValue($xpath, 'documents'));
        $this->assertSame('2', $this->metricValue($xpath, 'item-lines'));
        $this->assertSame('1,000.00', $this->metricValue($xpath, 'grand-total'));
        $this->assertSame('1,000.00', $this->metricValue($xpath, 'taxable-total'));
        $this->assertSame('0.00', $this->metricValue($xpath, 'tax-total'));
        $this->assertSame('1,000.00', $this->metricValue($xpath, 'paid'));
        $this->assertSame('0.00', $this->metricValue($xpath, 'outstanding'));
        $this->assertSame('0', $this->metricValue($xpath, 'blocking-errors'));
        $this->assertGreaterThan(0, (int) $this->metricValue($xpath, 'warnings'));
        $this->assertGreaterThan(0, (int) $this->metricValue($xpath, 'informational-findings'));
        $this->assertSame('2024-06-15', $this->metricValue($xpath, 'date'));
        $this->assertSame('2024-25', $this->metricValue($xpath, 'financial-year'));

        $summary = $this->firstNode($xpath, "//*[@data-historical-batch-summary='manual']");
        foreach (['Upload', 'Map', 'Rows', 'Header-only', 'Staged rows', 'Ignored / informational columns', 'Reconciliation preview'] as $importOnly) {
            $this->assertStringNotContainsString($importOnly, $summary->textContent);
        }
        $this->assertSame(0, $xpath->query("//*[@data-historical-register='staged-desktop' or @data-historical-register='staged-mobile']")?->length);

        $documents = $this->firstNode($xpath, "//*[@data-historical-batch-documents='manual']");
        $this->assertStringContainsString('Saved historical bill', $documents->textContent);
        $this->assertStringContainsString('2024-06-15', $documents->textContent);
        $this->assertStringContainsString('1,000.00', $documents->textContent);
        $this->assertGreaterThanOrEqual(1, $xpath->query("//*[@data-historical-batch-documents='manual']//a[@href='" . route('historical.documents.show', $document) . "']")?->length ?? 0);

        $page = $this->firstNode($xpath, "//*[@data-historical-batch-page]");
        $pageClasses = preg_split('/\s+/', trim($page->getAttribute('class'))) ?: [];
        $this->assertContains('min-w-0', $pageClasses);
        $this->firstNode($xpath, "//*[@data-historical-batch-actions][contains(concat(' ', normalize-space(@class), ' '), ' flex-wrap ')]");
        $this->assertSame(
            0,
            $xpath->query("//*[@data-historical-batch-actions]//a[not(contains(concat(' ', normalize-space(@class), ' '), ' min-h-[44px] '))] | //*[@data-historical-batch-actions]//button[not(contains(concat(' ', normalize-space(@class), ' '), ' min-h-[44px] '))]")?->length
        );
        $this->assertSame(
            0,
            $xpath->query("//*[@data-historical-batch-actions]//a[not(contains(concat(' ', normalize-space(@class), ' '), ' h-11 '))] | //*[@data-historical-batch-actions]//button[not(contains(concat(' ', normalize-space(@class), ' '), ' h-11 '))]")?->length
        );
        $this->firstNode(
            $xpath,
            "//a[@href='" . route('historical.index') . "'][contains(concat(' ', normalize-space(@class), ' '), ' h-11 ')]"
        );
    }

    public function test_manual_warning_acknowledgement_keeps_the_existing_publish_gate(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch] = $this->storeManualReview($owner, $shop->id);
        $this->assertGreaterThan(0, (int) $batch->warning_count);

        $before = $this->actingAs($owner)->get(route('historical.batches.show', $batch))->assertOk();
        $beforeXpath = $this->xpath($before->getContent());
        $this->firstNode($beforeXpath, "//form[@action='" . route('historical.batches.acknowledge', $batch) . "']");
        $this->firstNode($beforeXpath, "//*[@data-historical-publish-blocker]");
        $this->assertSame(0, $beforeXpath->query("//form[@action='" . route('historical.batches.publish', $batch) . "']")?->length);

        $this->actingAs($owner)
            ->post(route('historical.batches.acknowledge', $batch))
            ->assertRedirect(route('historical.batches.show', $batch));

        $after = $this->actingAs($owner)->get(route('historical.batches.show', $batch))->assertOk();
        $afterXpath = $this->xpath($after->getContent());
        $this->assertSame(0, $afterXpath->query("//form[@action='" . route('historical.batches.acknowledge', $batch) . "']")?->length);
        $this->firstNode($afterXpath, "//form[@action='" . route('historical.batches.publish', $batch) . "']");
        $this->firstNode($afterXpath, "//form[@action='" . route('historical.batches.destroy', $batch) . "']");
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

    public function test_manual_batch_actions_remain_permission_gated(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch] = $this->storeManualReview($owner, $shop->id);
        $this->grantOnlyPermissions($owner, ['historical.view']);

        $response = $this->actingAs($owner)->get(route('historical.batches.show', $batch))->assertOk();
        $xpath = $this->xpath($response->getContent());

        foreach (['acknowledge', 'destroy', 'publish', 'normalize'] as $action) {
            $routeName = "historical.batches.{$action}";
            $this->assertSame(0, $xpath->query("//form[@action='" . route($routeName, $batch) . "']")?->length);
        }
        $this->firstNode($xpath, "//*[@data-historical-batch-summary='manual']");
    }

    public function test_published_manual_batch_is_read_only(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch, $document] = $this->storeManualReview($owner, $shop->id);

        if ((int) $batch->warning_count > 0) {
            $this->actingAs($owner)->post(route('historical.batches.acknowledge', $batch))->assertRedirect();
        }
        $this->actingAs($owner)->post(route('historical.batches.publish', $batch))->assertRedirect();

        $response = $this->actingAs($owner)->get(route('historical.batches.show', $batch))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->firstNode($xpath, "//*[@data-historical-step='publish']//*[@aria-current='step']");
        $this->assertStringContainsString('Published', $this->firstNode($xpath, "//*[@data-historical-batch-context='manual']")->textContent);
        foreach (['acknowledge', 'destroy', 'publish', 'normalize'] as $action) {
            $routeName = "historical.batches.{$action}";
            $this->assertSame(0, $xpath->query("//form[@action='" . route($routeName, $batch) . "']")?->length);
        }
        $this->assertGreaterThanOrEqual(1, $xpath->query("//a[@href='" . route('historical.documents.show', $document) . "']")?->length ?? 0);
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

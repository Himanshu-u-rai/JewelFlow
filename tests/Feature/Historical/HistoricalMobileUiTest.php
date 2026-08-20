<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Services\Historical\HistoricalDuplicateDetector;
use App\Support\TenantContext;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class HistoricalMobileUiTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'Rendered HTML could not be parsed.');

        return new DOMXPath($document);
    }

    /** @param list<string> $tokens */
    private function assertNodesHaveClasses(DOMXPath $xpath, string $expression, array $tokens): void
    {
        $nodes = $xpath->query($expression);

        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "No rendered nodes matched {$expression}");

        foreach ($nodes as $node) {
            $this->assertInstanceOf(DOMElement::class, $node);
            $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];

            foreach ($tokens as $token) {
                $this->assertContains($token, $classes, "Missing {$token} on rendered <{$node->tagName}>.");
            }
        }
    }

    /** @return list<string> */
    private function optionValues(DOMXPath $xpath, string $selectId): array
    {
        $options = $xpath->query("//*[@id='{$selectId}']/option");
        $this->assertNotFalse($options);

        $values = [];
        foreach ($options as $option) {
            $this->assertInstanceOf(DOMElement::class, $option);
            $values[] = $option->getAttribute('value');
        }

        return $values;
    }

    private function assertMapShrinkContract(string $html): DOMXPath
    {
        $xpath = $this->xpath($html);
        $root = "//*[contains(concat(' ', normalize-space(@class), ' '), ' historical-map-page ')]";

        $this->assertNodesHaveClasses($xpath, "{$root}//form", ['min-w-0', 'max-w-full']);
        $this->assertNodesHaveClasses($xpath, "{$root}//fieldset", ['min-w-0', 'max-w-full']);
        $this->assertNodesHaveClasses($xpath, "{$root}//fieldset//div[contains(concat(' ', normalize-space(@class), ' '), ' grid ')]", ['min-w-0', 'max-w-full']);
        $this->assertNodesHaveClasses($xpath, "{$root}//fieldset//div[contains(concat(' ', normalize-space(@class), ' '), ' grid ')]/div", ['min-w-0', 'max-w-full']);
        $this->assertNodesHaveClasses($xpath, "{$root}//label", ['min-w-0', 'max-w-full']);
        $this->assertNodesHaveClasses($xpath, "{$root}//select", ['w-full', 'min-w-0', 'max-w-full', 'min-h-[44px]']);
        $this->assertNodesHaveClasses($xpath, "{$root}//input[@type='text' or @type='number']", ['w-full', 'min-w-0', 'max-w-full']);
        $this->assertNodesHaveClasses($xpath, "{$root}//select[contains(concat(' ', normalize-space(@class), ' '), ' js-mapping-field ')]", ['min-w-0', 'max-w-full']);

        return $xpath;
    }

    private function assertTapTargets(string $html): void
    {
        $xpath = $this->xpath($html);
        $this->assertNodesHaveClasses(
            $xpath,
            "//button[contains(concat(' ', normalize-space(@class), ' '), ' btn ')] | //a[contains(concat(' ', normalize-space(@class), ' '), ' btn ')] | //select | //input[@type='file']",
            ['min-h-[44px]']
        );
    }

    private function firstNode(DOMXPath $xpath, string $expression): DOMElement
    {
        $nodes = $xpath->query($expression);

        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Expected one rendered node for {$expression}.");
        $this->assertInstanceOf(DOMElement::class, $nodes->item(0));

        return $nodes->item(0);
    }

    private function buildTwoSheetXlsx(): string
    {
        $spreadsheet = new Spreadsheet();
        $header = $spreadsheet->getActiveSheet();
        $header->setTitle('Invoices Archive');
        $header->fromArray(['InvoiceNo', 'InvoiceDate', 'GrandTotal'], null, 'A1');
        $header->fromArray(['INV-1', '2023-06-15', 15000], null, 'A2');

        $longDetailHeader = 'Legacy Jewellery Description From Previous Accounting Software With Extra Context';
        $detail = $spreadsheet->createSheet();
        $detail->setTitle('Line Items Archive');
        $detail->fromArray(['InvoiceNo', $longDetailHeader, 'LineTotal'], null, 'A1');
        $detail->fromArray(['INV-1', 'Gold Ring', 15000], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'jf_mobile_ui_');
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

    private function uploadFile($owner, int $shopId, UploadedFile $file): HistoricalImportBatch
    {
        $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file' => $file,
            'label' => 'Mobile UI fixture',
            'source_system' => 'Legacy ERP',
        ])->assertRedirect();

        return TenantContext::runFor(
            $shopId,
            fn () => HistoricalImportBatch::query()->latest('id')->firstOrFail()
        );
    }

    private function makeBatch(int $shopId, int $actorId, array $attributes = []): HistoricalImportBatch
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $actorId, $attributes): HistoricalImportBatch {
            $batch = new HistoricalImportBatch();
            $batch->forceFill(array_merge([
                'shop_id' => $shopId,
                'label' => 'Mobile review batch',
                'source_system' => 'Legacy ERP',
                'status' => HistoricalImportBatch::STATUS_REVIEW,
                'created_by' => $actorId,
                'preview_generated_at' => now(),
                'blocking_count' => 0,
                'warning_count' => 1,
            ], $attributes))->save();

            return $batch;
        });
    }

    private function makeDocument(int $shopId, int $batchId, array $attributes = []): HistoricalSalesDocument
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $batchId, $attributes): HistoricalSalesDocument {
            $status = $attributes['status'] ?? HistoricalSalesDocument::STATUS_DRAFT;
            $number = $attributes['original_document_number'] ?? ('MOBILE-' . Str::random(8));

            $document = new HistoricalSalesDocument();
            $document->forceFill(array_merge([
                'shop_id' => $shopId,
                'historical_import_batch_id' => $batchId,
                'historical_reference' => (string) Str::uuid(),
                'original_document_number' => $number,
                'original_document_number_normalized' => $number,
                'document_type' => HistoricalSalesDocument::TYPE_SALE_INVOICE,
                'document_date' => '2023-06-15',
                'financial_year' => '2023-24',
                'source_system' => 'Legacy ERP',
                'customer_snapshot' => ['name' => 'Mobile Customer'],
                'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
                'tax_completeness' => HistoricalSalesDocument::TAX_UNKNOWN,
                'grand_total' => 15000,
                'status' => $status,
                'content_fingerprint' => hash('sha256', (string) Str::uuid()),
                'published_at' => $status === HistoricalSalesDocument::STATUS_PUBLISHED ? now() : null,
            ], $attributes))->save();

            return $document;
        });
    }

    private function makeLine(int $shopId, int $documentId, array $attributes = []): HistoricalSalesLine
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $documentId, $attributes): HistoricalSalesLine {
            $line = new HistoricalSalesLine();
            $line->forceFill(array_merge([
                'shop_id' => $shopId,
                'historical_sales_document_id' => $documentId,
                'line_number' => 1,
                'item_snapshot' => ['name' => 'Archive Gold Ring'],
                'source_sku' => 'OLD-RING-1',
                'hsn_snapshot' => '7113',
                'quantity' => 2,
                'net_weight' => 4.250,
                'line_total' => 9876.50,
            ], $attributes))->save();

            return $line;
        });
    }

    public function test_layout_c_mapping_has_complete_shrink_chain_and_keeps_sheet_options(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->uploadFile(
            $owner,
            $shop->id,
            UploadedFile::fake()->createWithContent('mobile-layout-c.xlsx', $this->buildTwoSheetXlsx())
        );

        $response = $this->actingAs($owner)->get(route('historical.batches.map', $batch));
        $response->assertOk();

        $xpath = $this->assertMapShrinkContract($response->getContent());
        $this->assertContains('Invoices Archive', $this->optionValues($xpath, 'map_sheet_header'));
        $this->assertContains('Line Items Archive', $this->optionValues($xpath, 'map_sheet_detail'));
        $this->assertContains(
            'Legacy Jewellery Description From Previous Accounting Software With Extra Context',
            $this->optionValues($xpath, 'map_field_line_item_name')
        );
    }

    public function test_layout_a_mapping_keeps_single_sheet_options_and_shrink_contract(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $csv = "InvoiceNo,InvoiceDate,GrandTotal\nINV-9,2023-01-01,5000\n";
        $batch = $this->uploadFile($owner, $shop->id, UploadedFile::fake()->createWithContent('layout-a.csv', $csv));

        $response = $this->actingAs($owner)->get(route('historical.batches.map', $batch));
        $response->assertOk();

        $xpath = $this->assertMapShrinkContract($response->getContent());
        $this->assertContains('InvoiceNo', $this->optionValues($xpath, 'map_field_original_document_number'));
        $this->assertContains('GrandTotal', $this->optionValues($xpath, 'map_field_grand_total'));
        $response->assertDontSee('<option value="Line Items Archive"', false);
    }

    public function test_entry_and_mapping_forms_use_native_sections_without_changing_contracts(): void
    {
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();

        $upload = $this->actingAs($owner)->get(route('historical.upload.create'))->assertOk();
        $uploadXpath = $this->xpath($upload->getContent());
        $uploadForm = $this->firstNode($uploadXpath, "//form[@data-historical-form='upload']");
        $this->assertSame('post', strtolower($uploadForm->getAttribute('method')));
        $this->assertSame(route('historical.upload.store'), $uploadForm->getAttribute('action'));
        $this->assertSame('multipart/form-data', $uploadForm->getAttribute('enctype'));
        $this->firstNode($uploadXpath, "//form[@data-historical-form='upload']//*[@data-historical-card-header]");
        $this->firstNode($uploadXpath, "//form[@data-historical-form='upload']//*[@data-historical-card-body]");
        $this->firstNode($uploadXpath, "//form[@data-historical-form='upload']//*[@data-historical-card-footer]");

        $manual = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $manualXpath = $this->xpath($manual->getContent());
        $manualForm = $this->firstNode($manualXpath, "//form[@data-historical-form='manual']");
        $this->assertSame(route('historical.manual.preview'), $manualForm->getAttribute('action'));
        $this->assertSame('false', $manualForm->getAttribute('data-turbo'));
        $this->assertSame('{ lines: [] }', $manualForm->getAttribute('x-data'));
        $this->assertGreaterThanOrEqual(6, $manualXpath->query("//form[@data-historical-form='manual']//fieldset[@data-historical-form-section]")?->length);
        $this->assertStringContainsString('@click="lines.push({})"', $manual->getContent());

        $batch = $this->uploadFile(
            $owner,
            $shop->id,
            UploadedFile::fake()->createWithContent('native-layout-c.xlsx', $this->buildTwoSheetXlsx())
        );
        $mapping = $this->actingAs($owner)->get(route('historical.batches.map', $batch))->assertOk();
        $mappingXpath = $this->assertMapShrinkContract($mapping->getContent());
        $mappingForm = $this->firstNode($mappingXpath, "//form[@data-historical-form='mapping']");
        $this->assertSame(route('historical.batches.map.save', $batch), $mappingForm->getAttribute('action'));
        $this->assertGreaterThanOrEqual(8, $mappingXpath->query("//form[@data-historical-form='mapping']//fieldset[@data-historical-form-section]")?->length);
        $this->assertSame(1, $mappingXpath->query("//select[@id='map_sheet_header' and @name='sheets[header]']")?->length);
        $this->assertSame(1, $mappingXpath->query("//select[@id='map_sheet_detail' and @name='sheets[detail]']")?->length);
        $this->assertGreaterThan(0, $mappingXpath->query("//select[contains(concat(' ', normalize-space(@class), ' '), ' js-mapping-field ') and @data-sheet-role]")?->length);
        $this->assertStringContainsString("refresh('header', this.value);", $mapping->getContent());
        $this->assertStringContainsString("refresh('detail', this.value);", $mapping->getContent());

        $workflow = $this->firstNode($mappingXpath, "//ol[@data-historical-workflow]");
        $this->assertContains('sm:grid-cols-4', preg_split('/\s+/', trim($workflow->getAttribute('class'))) ?: []);
        $this->assertSame(4, $mappingXpath->query("//ol[@data-historical-workflow]/li")?->length);
        $this->assertSame(1, $mappingXpath->query("//ol[@data-historical-workflow]//*[@aria-current='step' and contains(normalize-space(.), 'Map')]")?->length);
    }

    public function test_manual_section_titles_render_inside_cards_instead_of_on_fieldset_borders(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $sectionQuery = "//form[@data-historical-form='manual']//fieldset[@data-historical-form-section]";
        $sections = $xpath->query($sectionQuery);

        $this->assertNotFalse($sections);
        $this->assertSame(6, $sections->length);
        $this->assertSame(6, $xpath->query($sectionQuery . "/legend[contains(concat(' ', normalize-space(@class), ' '), ' sr-only ')]")?->length);
        $this->assertSame(6, $xpath->query($sectionQuery . "/*[@data-historical-card-header]")?->length);
        $this->assertSame(0, $xpath->query($sectionQuery . "/legend[contains(concat(' ', normalize-space(@class), ' '), ' w-full ')]")?->length);

        $headings = $xpath->query($sectionQuery . "/*[@data-historical-card-header]");
        $headingText = '';
        foreach ($headings ?: [] as $heading) {
            $headingText .= ' ' . $heading->textContent;
        }

        foreach (['Document identity', 'Customer snapshot', 'Amount / payment', 'Tax and making / labour charge', 'Item lines', 'Cutover'] as $title) {
            $this->assertStringContainsString($title, $headingText);
        }
    }

    public function test_manual_form_uses_independent_columns_to_avoid_short_card_row_gaps(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $layout = $this->firstNode($xpath, "//form[@data-historical-form='manual']//*[@data-historical-manual-layout]");
        $layoutClasses = preg_split('/\s+/', trim($layout->getAttribute('class'))) ?: [];

        foreach (['grid', 'grid-cols-1', 'gap-4', 'items-start', 'lg:grid-cols-3'] as $class) {
            $this->assertContains($class, $layoutClasses);
        }

        $primary = $this->firstNode($xpath, "//*[@data-historical-manual-layout]/*[@data-historical-primary-column]");
        $supporting = $this->firstNode($xpath, "//*[@data-historical-manual-layout]/*[@data-historical-supporting-column]");
        foreach (['grid', 'grid-cols-1', 'gap-4', 'lg:col-span-2'] as $class) {
            $this->assertContains($class, preg_split('/\s+/', trim($primary->getAttribute('class'))) ?: []);
        }
        foreach (['grid', 'grid-cols-1', 'gap-4', 'lg:col-span-1'] as $class) {
            $this->assertContains($class, preg_split('/\s+/', trim($supporting->getAttribute('class'))) ?: []);
        }

        $this->assertSame(3, $xpath->query("//*[@data-historical-primary-column]/fieldset[@data-historical-form-section]")?->length);
        $this->assertSame(3, $xpath->query("//*[@data-historical-supporting-column]/fieldset[@data-historical-form-section]")?->length);
        $this->assertSame(0, $xpath->query("//*[@data-historical-manual-layout]/fieldset[@data-historical-form-section]")?->length);

        foreach (['document', 'amounts', 'items'] as $section) {
            $this->firstNode($xpath, "//*[@data-historical-primary-column]/fieldset[@data-historical-section='{$section}']");
        }
        foreach (['customer', 'tax-making', 'cutover'] as $section) {
            $this->firstNode($xpath, "//*[@data-historical-supporting-column]/fieldset[@data-historical-section='{$section}']");
        }

        $this->assertNodesHaveClasses($xpath, "//*[@data-historical-section='tax-making']//*[@data-historical-supporting-grid]", ['lg:grid-cols-1']);
        $this->assertNodesHaveClasses($xpath, "//*[@data-historical-section='cutover']//*[@data-historical-supporting-grid]", ['lg:grid-cols-1']);
    }

    public function test_manual_form_controls_override_the_shared_page_surface_tokens(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $layout = $this->firstNode($xpath, "//form[@data-historical-form='manual']//*[@data-historical-manual-layout]");
        $style = $layout->getAttribute('style');

        $this->assertStringContainsString('--app-control-bg: #ffffff', $style);
        $this->assertStringContainsString('--app-control-border: #cbd5e1', $style);
        $this->assertStringContainsString('--app-control-border-focus: #b45309', $style);
    }

    public function test_upload_manual_preview_and_index_actions_have_mobile_tap_targets(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->makeBatch($shop->id, $owner->id, ['warning_count' => 0]);
        $document = $this->makeDocument($shop->id, $batch->id, [
            'original_document_number' => 'MOBILE-INDEX',
        ]);

        $this->assertTapTargets($this->actingAs($owner)->get(route('historical.upload.create'))->assertOk()->getContent());
        $this->assertTapTargets($this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent());
        $index = $this->actingAs($owner)->get(route('historical.index'))->assertOk();
        $this->assertTapTargets($index->getContent());
        $indexXpath = $this->xpath($index->getContent());
        $this->assertNodesHaveClasses($indexXpath, "//a[@href='" . route('historical.batches.show', $batch) . "']", ['inline-flex', 'items-center', 'min-h-[44px]']);
        $this->assertNodesHaveClasses($indexXpath, "//a[@href='" . route('historical.documents.show', $document) . "']", ['inline-flex', 'items-center', 'min-h-[44px]']);

        $preview = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'original_document_number' => 'MOBILE-PREVIEW-1',
            'document_date' => '2023-06-15',
            'source_system' => 'Manual',
            'customer_name' => 'Mobile Customer',
            'grand_total' => 18000,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
        ])->assertOk();
        $this->assertTapTargets($preview->getContent());
    }

    public function test_index_registers_keep_equivalent_desktop_tables_and_mobile_cards(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->makeBatch($shop->id, $owner->id, [
            'label' => 'FY 2023 archive',
            'warning_count' => 0,
        ]);
        $document = $this->makeDocument($shop->id, $batch->id, [
            'original_document_number' => 'HIST-MOBILE-42',
            'customer_snapshot' => ['name' => 'Asha Jewels'],
            'grand_total' => 15420.75,
        ]);

        $response = $this->actingAs($owner)->get(route('historical.index'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        foreach (['batches', 'documents'] as $surface) {
            $desktop = $this->firstNode($xpath, "//*[@data-historical-register='{$surface}-desktop']");
            $mobile = $this->firstNode($xpath, "//*[@data-historical-register='{$surface}-mobile']");

            $this->assertContains('hidden', preg_split('/\s+/', trim($desktop->getAttribute('class'))) ?: []);
            $this->assertContains('md:block', preg_split('/\s+/', trim($desktop->getAttribute('class'))) ?: []);
            $this->assertContains('md:hidden', preg_split('/\s+/', trim($mobile->getAttribute('class'))) ?: []);
        }

        $batchUrl = route('historical.batches.show', $batch);
        $documentUrl = route('historical.documents.show', $document);
        foreach (['batches-desktop', 'batches-mobile'] as $surface) {
            $node = $this->firstNode($xpath, "//*[@data-historical-register='{$surface}']");
            $this->assertStringContainsString('FY 2023 archive', $node->textContent);
            $this->assertSame(1, $xpath->query(".//a[@href='{$batchUrl}']", $node)?->length);
        }
        foreach (['documents-desktop', 'documents-mobile'] as $surface) {
            $node = $this->firstNode($xpath, "//*[@data-historical-register='{$surface}']");
            $this->assertStringContainsString('HIST-MOBILE-42', $node->textContent);
            $this->assertStringContainsString('Asha Jewels', $node->textContent);
            $this->assertStringContainsString('15,420.75', $node->textContent);
            $this->assertSame(1, $xpath->query(".//a[@href='{$documentUrl}']", $node)?->length);
        }

        $this->assertNodesHaveClasses(
            $xpath,
            "//*[@data-historical-register='batches-desktop' or @data-historical-register='documents-desktop']//th",
            ['normal-case', 'tracking-normal', 'text-xs', 'font-semibold']
        );
        $this->assertNodesHaveClasses(
            $xpath,
            "//*[@data-historical-register='batches-desktop' or @data-historical-register='documents-desktop']//a",
            ['border', 'rounded-lg', 'min-h-[44px]']
        );
    }

    public function test_batch_review_controls_have_mobile_tap_targets(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->makeBatch($shop->id, $owner->id);

        TenantContext::runFor($shop->id, function () use ($shop, $batch): void {
            $row = new HistoricalImportRow();
            $row->forceFill([
                'shop_id' => $shop->id,
                'historical_import_batch_id' => $batch->id,
                'source_sheet' => 'Sheet1',
                'source_row_number' => 2,
                'grouping_key' => 'duplicate-mobile-ui',
                'original_payload' => ['InvoiceNo' => 'DUP-1'],
                'normalized_payload' => ['original_document_number' => 'DUP-1'],
                'severity' => HistoricalImportRow::SEVERITY_WARNING,
                'validation_status' => HistoricalImportRow::VALIDATION_VALID,
                'messages' => [[
                    'severity' => HistoricalImportRow::SEVERITY_WARNING,
                    'code' => HistoricalDuplicateDetector::CODE_DUPLICATE_NUMBER,
                    'text' => 'Duplicate fixture.',
                    'field' => null,
                ]],
            ])->save();
        });

        $response = $this->actingAs($owner)->get(route('historical.batches.show', $batch))->assertOk();
        $this->assertTapTargets($response->getContent());
    }

    public function test_batch_review_keeps_equivalent_staged_rows_and_document_links_across_breakpoints(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->makeBatch($shop->id, $owner->id, ['warning_count' => 0]);
        $document = $this->makeDocument($shop->id, $batch->id, [
            'original_document_number' => 'BATCH-HIST-88',
            'grand_total' => 7654.25,
        ]);

        TenantContext::runFor($shop->id, function () use ($shop, $batch): void {
            $row = new HistoricalImportRow();
            $row->forceFill([
                'shop_id' => $shop->id,
                'historical_import_batch_id' => $batch->id,
                'source_sheet' => 'Legacy Sales',
                'source_row_number' => 17,
                'grouping_key' => 'review-mobile-row',
                'original_payload' => ['InvoiceNo' => 'BATCH-HIST-88'],
                'normalized_payload' => ['original_document_number' => 'BATCH-HIST-88'],
                'severity' => HistoricalImportRow::SEVERITY_WARNING,
                'validation_status' => HistoricalImportRow::VALIDATION_VALID,
                'messages' => [[
                    'severity' => HistoricalImportRow::SEVERITY_WARNING,
                    'code' => 'review_fixture',
                    'text' => 'Check the archived tax summary.',
                    'field' => null,
                ]],
            ])->save();
        });

        $response = $this->actingAs($owner)->get(route('historical.batches.show', $batch))->assertOk();
        $xpath = $this->xpath($response->getContent());

        foreach (['staged-desktop', 'staged-mobile'] as $surface) {
            $node = $this->firstNode($xpath, "//*[@data-historical-register='{$surface}']");
            $this->assertStringContainsString('Legacy Sales', $node->textContent);
            $this->assertStringContainsString('17', $node->textContent);
            $this->assertStringContainsString('Check the archived tax summary.', $node->textContent);
        }
        foreach (['documents-desktop', 'documents-mobile'] as $surface) {
            $node = $this->firstNode($xpath, "//*[@data-historical-batch-register='{$surface}']");
            $this->assertStringContainsString('BATCH-HIST-88', $node->textContent);
            $this->assertStringContainsString('7,654.25', $node->textContent);
            $this->assertSame(1, $xpath->query(".//a[@href='" . route('historical.documents.show', $document) . "']", $node)?->length);
        }

        $this->assertNodesHaveClasses($xpath, "//*[@data-historical-register='staged-desktop']", ['hidden', 'md:block']);
        $this->assertNodesHaveClasses($xpath, "//*[@data-historical-register='staged-mobile']", ['md:hidden']);
    }

    public function test_preview_and_document_keep_reference_hierarchy_and_single_mutation_controls(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $preview = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'original_document_number' => 'PREVIEW-HIST-9',
            'document_date' => '2023-06-15',
            'source_system' => 'Manual',
            'customer_name' => 'Preview Customer',
            'grand_total' => 9876.50,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'lines' => [[
                'line_item_name' => 'Archive Gold Ring',
                'line_quantity' => 2,
                'line_net_weight' => 4.25,
                'line_total' => 9876.50,
            ]],
        ])->assertOk();
        $previewXpath = $this->xpath($preview->getContent());
        $this->firstNode($previewXpath, "//*[@data-historical-preview-layout]");
        foreach (['lines-desktop', 'lines-mobile'] as $surface) {
            $node = $this->firstNode($previewXpath, "//*[@data-historical-preview-register='{$surface}']");
            $this->assertStringContainsString('Archive Gold Ring', $node->textContent);
            $this->assertStringContainsString('9,876.50', $node->textContent);
        }
        $previewForm = $this->firstNode($previewXpath, "//form[@data-historical-form='manual-preview']");
        $this->assertSame(route('historical.manual.preview'), $previewForm->getAttribute('action'));
        $this->assertSame('false', $previewForm->getAttribute('data-turbo'));
        $this->assertSame(1, $previewXpath->query("//button[@formaction='" . route('historical.manual.store') . "']")?->length);

        $batch = $this->makeBatch($shop->id, $owner->id, ['warning_count' => 0]);
        $document = $this->makeDocument($shop->id, $batch->id, [
            'original_document_number' => 'DOC-HIST-55',
            'grand_total' => 9876.50,
        ]);
        $this->makeLine($shop->id, $document->id);

        $documentPage = $this->actingAs($owner)->get(route('historical.documents.show', $document))->assertOk();
        $documentXpath = $this->xpath($documentPage->getContent());
        $layout = $this->firstNode($documentXpath, "//*[@data-historical-document-layout]");
        $this->assertContains('lg:grid-cols-3', preg_split('/\s+/', trim($layout->getAttribute('class'))) ?: []);
        $this->assertStringContainsString(HistoricalSalesDocument::RECORD_DISCLAIMER, $documentPage->getContent());
        foreach (['lines-desktop', 'lines-mobile'] as $surface) {
            $node = $this->firstNode($documentXpath, "//*[@data-historical-document-register='{$surface}']");
            $this->assertStringContainsString('Archive Gold Ring', $node->textContent);
            $this->assertStringContainsString('OLD-RING-1', $node->textContent);
            $this->assertStringContainsString('9,876.50', $node->textContent);
        }

        $published = $this->makeDocument($shop->id, $batch->id, [
            'status' => HistoricalSalesDocument::STATUS_PUBLISHED,
            'original_document_number' => 'DOC-PUBLISHED-56',
        ]);
        $publishedPage = $this->actingAs($owner)->get(route('historical.documents.show', $published))->assertOk();
        $publishedXpath = $this->xpath($publishedPage->getContent());
        $this->assertSame(1, $publishedXpath->query("//form[@action='" . route('historical.documents.void', $published) . "']")?->length);
        $this->assertSame(1, $publishedXpath->query("//form[@action='" . route('historical.documents.supersede', $published) . "']")?->length);
    }

    public function test_document_action_tap_targets_preserve_permission_and_terminal_gates(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->makeBatch($shop->id, $owner->id, ['warning_count' => 0]);
        $published = $this->makeDocument($shop->id, $batch->id, [
            'status' => HistoricalSalesDocument::STATUS_PUBLISHED,
            'original_document_number' => 'MOBILE-PUBLISHED',
        ]);

        $publishedPage = $this->actingAs($owner)->get(route('historical.documents.show', $published))->assertOk();
        $publishedPage->assertSee(route('historical.documents.void', $published), false);
        $publishedPage->assertSee(route('historical.documents.supersede', $published), false);
        $this->assertTapTargets($publishedPage->getContent());

        $this->grantOnlyPermissions($owner, ['historical.view']);
        $readOnlyPage = $this->actingAs($owner->fresh())->get(route('historical.documents.show', $published))->assertOk();
        $readOnlyPage->assertDontSee(route('historical.documents.void', $published), false);
        $readOnlyPage->assertDontSee(route('historical.documents.supersede', $published), false);

        [$draftOwner, $draftShop] = $this->createRetailerTenant();
        $draftBatch = $this->makeBatch($draftShop->id, $draftOwner->id, ['warning_count' => 0]);
        $draft = $this->makeDocument($draftShop->id, $draftBatch->id, [
            'original_document_number' => 'MOBILE-DRAFT',
        ]);
        $draftPage = $this->actingAs($draftOwner)->get(route('historical.documents.show', $draft))->assertOk();
        $draftPage->assertDontSee(route('historical.documents.void', $draft), false);
        $draftPage->assertDontSee(route('historical.documents.supersede', $draft), false);
        $this->assertTapTargets($draftPage->getContent());
    }
}

<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument;
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

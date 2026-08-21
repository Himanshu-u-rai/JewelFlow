<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class HistoricalManualDocumentUiTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    private const BLOCKING_TEXT = 'Correct the archived amount before saving.';
    private const WARNING_TEXT = 'Confirm the archived tax treatment.';
    private const INFO_TEXT = 'Customer details remain a historical snapshot.';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, mixed> */
    private function manualPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'original_document_number' => 'UI-PREVIEW-100',
            'document_date' => '2024-06-15',
            'source_system' => 'Manual archive',
            'customer_name' => 'Archive Customer',
            'grand_total' => 1000,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'lines' => [],
        ], $overrides);
    }

    /** @return list<array{severity: string, code: string, text: string, field: ?string}> */
    private function findings(): array
    {
        return [
            ['severity' => 'error', 'code' => 'ui_blocking', 'text' => self::BLOCKING_TEXT, 'field' => 'grand_total'],
            ['severity' => 'warning', 'code' => 'ui_warning', 'text' => self::WARNING_TEXT, 'field' => 'tax_mode'],
            ['severity' => 'info', 'code' => 'ui_info', 'text' => self::INFO_TEXT, 'field' => null],
        ];
    }

    private function makeBatch(int $shopId, int $ownerId, array $overrides = []): HistoricalImportBatch
    {
        $messages = $overrides['messages'] ?? $this->findings();
        unset($overrides['messages']);

        $batch = new HistoricalImportBatch();
        $batch->forceFill(array_merge([
            'shop_id' => $shopId,
            'label' => 'Manual UI review',
            'source_system' => 'Manual archive',
            'source_file_name' => null,
            'status' => HistoricalImportBatch::STATUS_REVIEW,
            'blocking_count' => count(array_filter($messages, fn (array $message): bool => $message['severity'] === 'error')),
            'warning_count' => count(array_filter($messages, fn (array $message): bool => $message['severity'] === 'warning')),
            'preview_generated_at' => now(),
            'preview_summary' => ['manual_messages' => $messages],
            'created_by' => $ownerId,
        ], $overrides))->save();

        return $batch;
    }

    private function makeDocument(int $shopId, int $batchId, int $ownerId, array $overrides = []): HistoricalSalesDocument
    {
        $number = $overrides['original_document_number'] ?? 'MANUAL-UI-' . Str::upper(Str::random(6));
        $date = $overrides['document_date'] ?? '2024-06-15';
        $status = $overrides['status'] ?? HistoricalSalesDocument::STATUS_DRAFT;

        $document = new HistoricalSalesDocument();
        $document->forceFill(array_merge([
            'shop_id' => $shopId,
            'historical_import_batch_id' => $batchId,
            'historical_reference' => (string) Str::uuid(),
            'original_document_number' => $number,
            'original_document_number_normalized' => HistoricalDocumentIdentity::normalizeNumber($number),
            'document_type' => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'document_date' => $date,
            'financial_year' => HistoricalDocumentIdentity::financialYearFor(new \DateTimeImmutable($date)),
            'source_system' => 'Manual archive',
            'customer_snapshot' => ['name' => 'Archive Customer'],
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness' => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total' => 1000,
            'status' => $status,
            'content_fingerprint' => hash('sha256', (string) Str::uuid()),
            'imported_by' => $ownerId,
            'imported_at' => now(),
            'published_at' => in_array($status, [
                HistoricalSalesDocument::STATUS_PUBLISHED,
                HistoricalSalesDocument::STATUS_SUPERSEDED,
            ], true) ? now() : null,
            'void_reason' => $status === HistoricalSalesDocument::STATUS_VOID ? 'Archived in error' : null,
            'voided_at' => $status === HistoricalSalesDocument::STATUS_VOID ? now() : null,
        ], $overrides))->save();

        return $document;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    private function firstNode(DOMXPath $xpath, string $expression): DOMElement
    {
        $nodes = $xpath->query($expression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$expression}");
        $this->assertSame(1, $nodes->length, "Expected one node for: {$expression}");
        $this->assertInstanceOf(DOMElement::class, $nodes->item(0));

        return $nodes->item(0);
    }

    private function firstNodeWithin(DOMXPath $xpath, DOMElement $context, string $expression): DOMElement
    {
        $nodes = $xpath->query($expression, $context);
        $this->assertNotFalse($nodes, "Invalid XPath: {$expression}");
        $this->assertSame(1, $nodes->length, "Expected one node for: {$expression}");
        $this->assertInstanceOf(DOMElement::class, $nodes->item(0));

        return $nodes->item(0);
    }

    public function test_index_and_entry_copy_describe_the_separated_manual_flow(): void
    {
        [$owner] = $this->createRetailerTenant();

        $index = $this->actingAs($owner)->get(route('historical.index'))->assertOk();
        $this->assertSame(2, substr_count($index->getContent(), 'No file imports yet'));
        $this->assertSame(2, substr_count($index->getContent(), 'Import a CSV or XLSX file to begin.'));
        $index->assertDontSee('Import a file or enter a bill to start one.', false);

        $entry = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $entry->assertSee('Preview saves nothing.', false);
        $entry->assertSee('Save a draft or, if permitted, save and publish from the preview.', false);
        $this->firstNode(
            $this->xpath($entry->getContent()),
            "//*[@data-historical-manual-next-step][contains(concat(' ', normalize-space(@class), ' '), ' min-h-[44px] ')]"
        );
    }

    public function test_preview_orders_review_content_and_keeps_warning_acknowledgement_with_its_warning(): void
    {
        [$owner] = $this->createRetailerTenant();
        $response = $this->actingAs($owner)->post(
            route('historical.manual.preview'),
            $this->manualPayload()
        )->assertOk();

        $digest = $response->viewData('warningDigest');
        $this->assertNotNull($digest);
        $xpath = $this->xpath($response->getContent());

        $this->firstNode($xpath, "//*[@data-historical-preview-summary]/following-sibling::*[1][@data-historical-preview-messages]");
        $warning = $this->firstNode($xpath, "//*[@data-historical-preview-finding='warnings']");
        $this->firstNodeWithin($xpath, $warning, ".//input[@type='checkbox'][@name='acknowledge_warnings'][@value='1'][@form='historical-manual-preview-form']");
        $this->firstNodeWithin($xpath, $warning, ".//input[@type='hidden'][@name='acknowledged_warning_digest'][@value='{$digest}'][@form='historical-manual-preview-form']");
        $this->firstNode($xpath, "//*[@data-historical-preview-finding='warnings']/following-sibling::*[1][@data-historical-preview-finding='informational']");

        $this->firstNode($xpath, "//*[@data-historical-preview-messages]/following-sibling::*[1][@data-historical-preview-layout]");
        $this->firstNode($xpath, "//*[@data-historical-preview-card='amounts']/following-sibling::*[1][@data-historical-preview-card='customer']");
        $this->firstNode($xpath, "//*[@data-historical-preview-card='customer']/following-sibling::*[1][@data-historical-preview-card='tax-making']");
        foreach (['amounts', 'customer', 'tax-making'] as $cardName) {
            $card = $this->firstNode($xpath, "//*[@data-historical-preview-card='{$cardName}']");
            $fields = $xpath->query(".//*[@data-historical-preview-field]", $card);
            $this->assertNotFalse($fields);
            $this->assertGreaterThanOrEqual(5, $fields->length);
            $this->assertStringContainsString('border', $fields->item(0)?->attributes?->getNamedItem('class')?->nodeValue ?? '');
        }
        $this->assertSame(0, $xpath->query("//*[@data-historical-preview-card='amounts']//table")?->length);
        $this->firstNode($xpath, "//*[@data-historical-preview-layout]/following-sibling::*[1][@data-historical-preview-items]");
        $this->firstNode($xpath, "//*[@data-historical-preview-items]/following-sibling::*[1][@data-historical-preview-editor-heading]");
        $this->firstNode($xpath, "//*[@data-historical-preview-editor-heading]/following-sibling::form[1][@id='historical-manual-preview-form']");
    }

    public function test_preview_actions_preserve_routes_intents_permissions_and_item_register(): void
    {
        [$owner] = $this->createRetailerTenant();
        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'lines' => [[
                'line_item_name' => 'Archive Gold Ring',
                'line_quantity' => 1,
                'line_total' => 1000,
            ]],
        ]))->assertOk();

        $xpath = $this->xpath($response->getContent());
        $form = $this->firstNode($xpath, "//form[@id='historical-manual-preview-form'][@action='" . route('historical.manual.preview') . "'][@data-turbo='false']");
        $this->firstNodeWithin($xpath, $form, ".//input[@type='hidden'][@name='_token']");

        $store = route('historical.manual.store');
        $draft = $this->firstNodeWithin($xpath, $form, ".//button[@data-historical-preview-action='draft'][@formaction='{$store}'][@name='intent'][@value='draft']");
        $publish = $this->firstNodeWithin($xpath, $form, ".//button[@data-historical-preview-action='publish'][@formaction='{$store}'][@name='intent'][@value='publish']");
        $this->assertStringContainsString('btn-primary', $draft->getAttribute('class'));
        $this->assertStringNotContainsString('btn-primary', $publish->getAttribute('class'));
        $this->assertStringContainsString('min-h-[44px]', $draft->getAttribute('class'));
        $this->assertStringContainsString('min-h-[44px]', $publish->getAttribute('class'));

        $this->firstNode($xpath, "//*[@data-historical-preview-register='lines-desktop']//*[contains(text(), 'Archive Gold Ring')]");
        $this->firstNode($xpath, "//*[@data-historical-preview-register='lines-mobile']//*[contains(text(), 'Archive Gold Ring')]");

        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);
        $restricted = $this->actingAs($owner->fresh())->post(
            route('historical.manual.preview'),
            $this->manualPayload(['original_document_number' => 'UI-PREVIEW-RESTRICTED'])
        )->assertOk();
        $restricted->assertSee('value="draft"', false);
        $restricted->assertDontSee('value="publish"', false);
    }

    public function test_manual_draft_renders_grouped_findings_acknowledgement_and_verbatim_blocker(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$batch, $document] = TenantContext::runFor($shop->id, function () use ($owner, $shop): array {
            $batch = $this->makeBatch($shop->id, $owner->id);

            return [$batch, $this->makeDocument($shop->id, $batch->id, $owner->id)];
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document))->assertOk();
        $lifecycle = $response->viewData('lifecycle');
        $xpath = $this->xpath($response->getContent());

        $this->firstNode($xpath, "//*[@data-historical-manual-lifecycle]");
        foreach (['identity', 'customer', 'amounts'] as $cardName) {
            $card = $this->firstNode($xpath, "//*[@data-historical-document-card='{$cardName}']");
            $fields = $xpath->query(".//*[@data-historical-document-field]", $card);
            $this->assertNotFalse($fields);
            $this->assertGreaterThanOrEqual(4, $fields->length);
            $this->assertStringContainsString('border', $fields->item(0)?->attributes?->getNamedItem('class')?->nodeValue ?? '');
        }
        $this->assertSame(0, $xpath->query("//*[@data-historical-document-card='amounts']//table")?->length);
        foreach ([
            'blocking' => self::BLOCKING_TEXT,
            'warnings' => self::WARNING_TEXT,
            'informational' => self::INFO_TEXT,
        ] as $severity => $text) {
            $node = $this->firstNode($xpath, "//*[@data-historical-findings='{$severity}']");
            $this->assertStringContainsString($text, $node->textContent);
        }

        $this->assertStringContainsString($lifecycle['publish_blocker'], $response->getContent());
        $ack = $this->firstNode($xpath, "//form[@data-historical-manual-action='acknowledge'][@action='" . route('historical.documents.acknowledge', $document) . "'][translate(@method, 'post', 'POST')='POST']");
        $this->firstNodeWithin($xpath, $ack, ".//input[@type='hidden'][@name='_token']");
        $ackButton = $this->firstNodeWithin($xpath, $ack, ".//button[@type='submit']");
        $this->assertStringContainsString('min-h-[44px]', $ackButton->getAttribute('class'));
        $this->assertSame(0, $xpath->query("//form[@data-historical-manual-action='publish']")?->length);
        $this->assertSame(0, $xpath->query("//*[@data-historical-workflow]")?->length);
        $this->assertSame(0, $xpath->query("//*[@data-historical-batch-summary]")?->length);
        $this->assertSame(1, (int) $batch->blocking_count);
    }

    public function test_acknowledged_publishable_draft_shows_timestamp_and_only_the_permitted_publish_action(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $acknowledgedAt = now()->startOfMinute();
        $document = TenantContext::runFor($shop->id, function () use ($acknowledgedAt, $owner, $shop): HistoricalSalesDocument {
            $batch = $this->makeBatch($shop->id, $owner->id, [
                'messages' => [[
                    'severity' => 'warning',
                    'code' => 'ui_warning',
                    'text' => self::WARNING_TEXT,
                    'field' => 'tax_mode',
                ]],
                'warnings_acknowledged_at' => $acknowledgedAt,
                'warnings_acknowledged_by' => $owner->id,
            ]);

            return $this->makeDocument($shop->id, $batch->id, $owner->id);
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document))->assertOk();
        $this->assertTrue($response->viewData('lifecycle')['can_publish']);
        $xpath = $this->xpath($response->getContent());
        $ackState = $this->firstNode($xpath, "//*[@data-historical-acknowledgement='complete']");
        $this->assertStringContainsString($acknowledgedAt->format('d M Y, H:i'), $ackState->textContent);
        $this->assertSame(0, $xpath->query("//form[@data-historical-manual-action='acknowledge']")?->length);

        $publish = $this->firstNode($xpath, "//form[@data-historical-manual-action='publish'][@action='" . route('historical.documents.publish', $document) . "'][translate(@method, 'post', 'POST')='POST']");
        $this->firstNodeWithin($xpath, $publish, ".//input[@type='hidden'][@name='_token']");
        $button = $this->firstNodeWithin($xpath, $publish, ".//button[@type='submit']");
        $this->assertStringContainsString('min-h-[44px]', $button->getAttribute('class'));

        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);
        $restricted = $this->actingAs($owner->fresh())->get(route('historical.documents.show', $document))->assertOk();
        $restricted->assertDontSee(route('historical.documents.publish', $document), false);
    }

    public function test_read_only_operator_gets_no_manual_mutation_controls(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, function () use ($owner, $shop): HistoricalSalesDocument {
            $batch = $this->makeBatch($shop->id, $owner->id, [
                'messages' => [[
                    'severity' => 'warning',
                    'code' => 'ui_warning',
                    'text' => self::WARNING_TEXT,
                    'field' => null,
                ]],
            ]);

            return $this->makeDocument($shop->id, $batch->id, $owner->id);
        });
        $this->grantOnlyPermissions($owner, ['historical.view']);

        $response = $this->actingAs($owner->fresh())->get(route('historical.documents.show', $document))->assertOk();
        $response->assertSee(self::WARNING_TEXT, false);
        $response->assertDontSee(route('historical.documents.acknowledge', $document), false);
        $response->assertDontSee(route('historical.documents.publish', $document), false);
    }

    public function test_terminal_manual_documents_keep_findings_as_reference_without_manual_actions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        foreach ([
            HistoricalSalesDocument::STATUS_PUBLISHED,
            HistoricalSalesDocument::STATUS_VOID,
            HistoricalSalesDocument::STATUS_SUPERSEDED,
        ] as $status) {
            $document = TenantContext::runFor($shop->id, function () use ($owner, $shop, $status): HistoricalSalesDocument {
                $batch = $this->makeBatch($shop->id, $owner->id);

                return $this->makeDocument($shop->id, $batch->id, $owner->id, ['status' => $status]);
            });

            $response = $this->actingAs($owner)->get(route('historical.documents.show', $document))->assertOk();
            $xpath = $this->xpath($response->getContent());
            $terminal = $this->firstNode($xpath, "//*[@data-historical-manual-terminal='{$status}']");
            $this->assertStringContainsString('reference', strtolower($terminal->textContent));
            $response->assertSee(self::BLOCKING_TEXT, false);
            $response->assertSee(self::WARNING_TEXT, false);
            $response->assertSee(self::INFO_TEXT, false);
            $response->assertDontSee(route('historical.documents.acknowledge', $document), false);
            $response->assertDontSee(route('historical.documents.publish', $document), false);
        }
    }

    public function test_file_import_document_never_receives_manual_review_controls(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $document = TenantContext::runFor($shop->id, function () use ($owner, $shop): HistoricalSalesDocument {
            $batch = $this->makeBatch($shop->id, $owner->id, ['source_file_name' => 'archive.csv']);

            return $this->makeDocument($shop->id, $batch->id, $owner->id);
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query("//*[@data-historical-manual-lifecycle]")?->length);
        $response->assertDontSee(route('historical.documents.acknowledge', $document), false);
        $response->assertDontSee(route('historical.documents.publish', $document), false);
    }
}

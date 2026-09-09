<?php

namespace Tests\Feature\Historical;

use App\Http\Requests\Historical\StoreManualHistoricalRequest;
use App\Models\Historical\HistoricalSalesDocument;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3 fast-entry — the rendered-markup half of the contract
 * (HISTORICAL-BATCH-3-UX-CONTRACT-V2 final tranche). The business logic
 * (what gets saved, what gets carried, what gets cleared) is proven by
 * HistoricalManualFastEntryWiringTest; this file proves the two forms
 * actually render the controls that logic depends on — same honest-DOM
 * pattern as HistoricalManualPaymentRowsUiTest.
 */
class HistoricalManualFastEntryUiTest extends TestCase
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
        $document = new DOMDocument;
        $loaded = $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'Rendered HTML could not be parsed.');

        return new DOMXPath($document);
    }

    private function fastEntryPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'FE-UI-'.fake()->unique()->numberBetween(1, 999999),
            'document_series' => 'A',
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed' => '1',
            'customer_name' => 'Fast Entry Customer',
            'taxable_amount' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'outstanding_amount' => 0,
            'lines' => [[
                'line_item_name' => 'QA Gold Item',
                'line_quantity' => 1,
                'line_total' => 1000,
            ]],
        ], $override);
    }

    // ------------------------------------------------------------------ buttons

    public function test_preview_screen_renders_both_and_new_buttons_alongside_the_existing_ones(): void
    {
        [$owner] = $this->createRetailerTenant();

        $preview = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->fastEntryPayload())
            ->assertOk();
        $html = $preview->getContent();
        $xpath = $this->xpath($html);
        $form = "//form[@data-historical-form='manual-preview']";
        $storeUrl = route('historical.manual.store');

        foreach ([
            'draft' => StoreManualHistoricalRequest::INTENT_DRAFT,
            'draft-and-new' => StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW,
            'publish' => StoreManualHistoricalRequest::INTENT_PUBLISH,
            'publish-and-new' => StoreManualHistoricalRequest::INTENT_PUBLISH_AND_NEW,
        ] as $action => $intent) {
            $button = $xpath->query("{$form}//button[@data-historical-preview-action='{$action}']")?->item(0);
            $this->assertNotNull($button, "No button with data-historical-preview-action=\"{$action}\".");
            $this->assertSame($intent, $button->getAttribute('value'));
            $this->assertSame('intent', $button->getAttribute('name'));
            $this->assertSame($storeUrl, $button->getAttribute('formaction'));
        }
    }

    public function test_publish_and_new_is_hidden_without_the_publish_permission_but_draft_and_new_still_shows(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);

        $preview = $this->actingAs($owner->fresh())
            ->post(route('historical.manual.preview'), $this->fastEntryPayload())
            ->assertOk();
        $html = $preview->getContent();
        $xpath = $this->xpath($html);
        $form = "//form[@data-historical-form='manual-preview']";

        $this->assertSame(0, $xpath->query("{$form}//button[@data-historical-preview-action='publish']")?->length);
        $this->assertSame(0, $xpath->query("{$form}//button[@data-historical-preview-action='publish-and-new']")?->length);
        $this->assertGreaterThan(0, $xpath->query("{$form}//button[@data-historical-preview-action='draft']")?->length);
        $this->assertGreaterThan(0, $xpath->query("{$form}//button[@data-historical-preview-action='draft-and-new']")?->length);
    }

    // ------------------------------------------------------------------ double-submit guard

    public function test_the_manual_entry_forms_submit_button_is_disabled_while_submitting(): void
    {
        [$owner] = $this->createRetailerTenant();

        $manual = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $html = $manual->getContent();
        $xpath = $this->xpath($html);
        $form = "//form[@data-historical-form='manual']";

        $this->assertStringContainsString('@submit="submitOnce($event)"', $html);

        $button = $xpath->query("{$form}//button[@data-historical-manual-next-step]")?->item(0);
        $this->assertNotNull($button);
        $this->assertSame('submitting', $button->getAttribute(':disabled'));
    }

    public function test_every_submit_button_on_the_preview_screen_is_disabled_while_submitting(): void
    {
        [$owner] = $this->createRetailerTenant();

        $preview = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->fastEntryPayload())->assertOk();
        $previewHtml = $preview->getContent();
        $this->assertStringContainsString('@submit="submitOnce($event)"', $previewHtml);

        $previewXpath = $this->xpath($previewHtml);
        $previewForm = "//form[@data-historical-form='manual-preview']";
        $previewButtons = $previewXpath->query("{$previewForm}//button[@type='submit']");
        $this->assertGreaterThan(0, $previewButtons?->length, 'No submit buttons found on the preview screen.');

        foreach ($previewButtons as $button) {
            $this->assertSame('submitting', $button->getAttribute(':disabled'), 'A preview submit button is missing :disabled="submitting".');
        }
    }

    // ------------------------------------------------------------------ carry-forward rendering

    public function test_a_plain_visit_to_the_create_form_shows_no_carried_forward_badge(): void
    {
        [$owner] = $this->createRetailerTenant();

        $manual = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $html = $manual->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame(0, $xpath->query('//*[@data-historical-carried-forward]')?->length);
        $this->assertStringNotContainsString('Carried from previous bill', $html);

        $numberInput = $xpath->query("//input[@id='original_document_number']")?->item(0);
        $this->assertNotNull($numberInput);
        $this->assertSame('', $numberInput->getAttribute('autofocus'));
        $this->assertFalse($numberInput->hasAttribute('autofocus'));
    }

    public function test_the_fresh_form_after_and_new_shows_the_badge_and_autofocuses_the_invoice_number(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW,
        ]))->assertRedirect(route('historical.manual.create'));

        $manual = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $html = $manual->getContent();
        $xpath = $this->xpath($html);

        $this->assertGreaterThan(0, $xpath->query('//*[@data-historical-carried-forward]')?->length);
        $this->assertStringContainsString('Carried from previous bill', $html);

        $numberInput = $xpath->query("//input[@id='original_document_number']")?->item(0);
        $this->assertNotNull($numberInput);
        $this->assertTrue($numberInput->hasAttribute('autofocus'));
    }

    public function test_the_carried_forward_flash_does_not_survive_a_second_page_load(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW,
        ]))->assertRedirect(route('historical.manual.create'));

        // First GET consumes the flash (session flash lifecycle) — a second,
        // unrelated visit to the same form must render as a plain blank form.
        $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $second = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();

        $html = $second->getContent();
        $this->assertStringNotContainsString('Carried from previous bill', $html);
    }

    // ------------------------------------------------------------------ sensitive-field clearing

    public function test_the_fresh_form_after_and_new_has_no_leftover_customer_payment_or_line_data(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW,
            'original_document_number' => 'FE-UI-CLEAR-0001',
            'customer_name' => 'Should Not Survive',
            'customer_mobile' => '9876543210',
            'customer_gstin' => '27ABCDE1234F1Z5',
            'customer_pan' => 'ABCDE1234F',
            'customer_address' => 'Should Not Survive Street',
            'place_of_supply' => 'Karnataka',
            'cutover_reason' => 'Should Not Survive Reason',
            'notes' => 'Should Not Survive Notes',
        ]))->assertRedirect(route('historical.manual.create'));

        $manual = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();
        $html = $manual->getContent();

        foreach ([
            'FE-UI-CLEAR-0001',
            'Should Not Survive',
            '9876543210',
            '27ABCDE1234F1Z5',
            'ABCDE1234F',
            'Should Not Survive Street',
            'Should Not Survive Reason',
            'Should Not Survive Notes',
        ] as $leftover) {
            $this->assertStringNotContainsString($leftover, $html, "\"{$leftover}\" leaked into the fresh fast-entry form.");
        }
    }
}

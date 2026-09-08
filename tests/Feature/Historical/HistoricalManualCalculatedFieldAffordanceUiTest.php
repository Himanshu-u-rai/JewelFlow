<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Requirement #2 — the calculated-field UI affordance.
 *
 * The state machine behind it (auto suggestion tracked, manual override kept,
 * suggestion refreshed so there is always something to reset back to) is already
 * covered by `HistoricalCalculationStateServiceTest` and
 * `HistoricalManualCalculationWiringTest`. What was NOT covered is that the form
 * actually RENDERS the controls that reach it: the Auto/Manual badge, the
 * `*_mode` input that posts the state, and the "Use automatic value" button that
 * calls back into it. Without this, the whole affordance could be deleted from
 * the Blade layer and every existing test would still pass.
 *
 * Honest-DOM assertions, same pattern as `HistoricalManualPaymentRowsUiTest`.
 * Alpine attributes (`@click`, `:name`) are asserted as source strings because
 * DOMDocument does not preserve them as addressable attribute names.
 *
 * ponytail: markup-contract only. It proves the button is wired to the right
 * field, not that clicking it restores the auto figure — that needs a browser
 * driver, add when one exists in this suite.
 */
class HistoricalManualCalculatedFieldAffordanceUiTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    /** Must stay in step with $calculatedDocumentFields in manual*.blade.php. */
    private const DOCUMENT_FIELDS = [
        'taxable_amount', 'tax_total', 'cgst', 'sgst', 'igst', 'discount',
        'metal_value', 'stone_value', 'grand_total', 'paid_amount', 'outstanding_amount',
    ];

    /** Line-level calculated fields, each with its own reset affordance. */
    private const LINE_FIELDS = [
        'line_total', 'line_billable_weight', 'line_metal_value', 'line_stone_value',
        'line_making_amount', 'line_wastage_amount', 'line_discount_amount', 'line_taxable',
    ];

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

    public function test_every_calculated_document_field_renders_mode_badge_input_and_reset_button(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $form = "//form[@data-historical-form='manual']";

        foreach (self::DOCUMENT_FIELDS as $field) {
            $this->assertSame(
                1,
                $xpath->query("{$form}//input[@type='hidden'][@name='{$field}_mode']")?->length,
                "No {$field}_mode input — the manual/auto state would never post."
            );
            $this->assertStringContainsString(
                "documentModes.{$field} === 'manual' ? 'Manual' : 'Auto'",
                $html,
                "No Auto/Manual badge for {$field}."
            );
            $this->assertStringContainsString(
                "@click=\"recalculateDocument('{$field}')\"",
                $html,
                "No \"Use automatic value\" button for {$field} — a manual override could never be undone."
            );
        }
    }

    public function test_plain_money_fields_get_no_calculated_field_affordance(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        // cess and rounding are deliberately outside $calculatedDocumentFields:
        // nothing derives them, so a reset-to-auto button would offer a figure
        // that does not exist. Proves the affordance tracks the list, not every input.
        foreach (['cess', 'rounding'] as $field) {
            $this->assertSame(1, $xpath->query("//input[@id='{$field}']")?->length, "{$field} is not on the form at all.");
            $this->assertSame(0, $xpath->query("//input[@type='hidden'][@name='{$field}_mode']")?->length);
            $this->assertStringNotContainsString("recalculateDocument('{$field}')", $html);
        }
    }

    public function test_every_calculated_line_field_renders_its_own_reset_button(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        foreach (self::LINE_FIELDS as $field) {
            $this->assertStringContainsString(
                "`lines[\${i}][{$field}_mode]`",
                $html,
                "No name binding for lines[i][{$field}_mode]."
            );
            $this->assertStringContainsString(
                "@click=\"recalculate(line, '{$field}')\"",
                $html,
                "No reset-to-auto button for {$field}."
            );
            $this->assertStringContainsString(
                "line.{$field}_mode === 'manual'",
                $html,
                "The {$field} button is not gated on the field actually being manual."
            );
        }
    }

    public function test_preview_screen_preserves_the_calculated_field_affordance(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'document_date' => '2023-06-15',
            'source_system' => 'Manual',
            'grand_total' => 1000,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
        ])->assertOk()->getContent();

        $xpath = $this->xpath($html);
        $form = "//form[@data-historical-form='manual-preview']";

        foreach (self::DOCUMENT_FIELDS as $field) {
            $this->assertSame(
                1,
                $xpath->query("{$form}//input[@type='hidden'][@name='{$field}_mode']")?->length,
                "Preview drops the {$field}_mode input — a manual override would silently revert on review."
            );
            $this->assertStringContainsString("@click=\"recalculateDocument('{$field}')\"", $html);
        }
    }
}

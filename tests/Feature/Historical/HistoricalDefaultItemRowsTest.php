<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Support\TenantContext;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Register-style manual item rows: create/preview start with one blank row
 * and append the next one as the operator types. This file proves seeding,
 * padding, blank-row filtering, and interaction wiring.
 *
 * PHPUnit never runs Alpine. Every test below either (a) asserts the exact
 * server-rendered HTML/attribute contract the client-side JS depends on, or
 * (b) asserts the shipped JS source implements the padding/blank-detection
 * rules byte-for-byte. Neither proves "typing in the browser grows a row" —
 * that is flagged explicitly in test_typing_triggered_auto_expansion_is_a_browser_only_contract().
 */
class HistoricalDefaultItemRowsTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, mixed> a valid manual-entry payload. */
    private function manualPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'ROW-'.fake()->unique()->numberBetween(1, 99999),
            'document_date' => '2023-06-15',
            'source_system' => 'Manual',
            'customer_name' => 'Walk-in Customer',
            'grand_total' => 18000,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
        ], $override);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_use_internal_errors(false);

        return new DOMXPath($dom);
    }

    private function firstNode(DOMXPath $xpath, string $expression): DOMElement
    {
        $nodes = $xpath->query($expression);
        $this->assertNotFalse($nodes, "Bad XPath expression: {$expression}");
        $this->assertSame(1, $nodes->count(), "Expected exactly one match for: {$expression}");

        /** @var DOMElement $node */
        $node = $nodes->item(0);

        return $node;
    }

    // -------------------------------------------------------- init wiring

    public function test_manual_create_form_wires_the_shared_item_form_component(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $form = $this->firstNode($this->xpath($html), '//form[@data-historical-form="manual"]');

        $this->assertStringStartsWith('historicalManualForm({', $form->getAttribute('x-data'));
        $this->assertStringContainsString('lines: []', $form->getAttribute('x-data'));
        $this->assertStringContainsString('minimumRows: 1', $form->getAttribute('x-data'));
        $this->assertSame('', $form->getAttribute('x-init'));
    }

    public function test_manual_preview_edit_form_shares_the_same_component(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload())
            ->assertOk()->getContent();
        $form = $this->firstNode($this->xpath($html), '//form[@data-historical-form="manual-preview"]');

        $this->assertStringStartsWith('historicalManualForm({', $form->getAttribute('x-data'));
        $this->assertStringContainsString('minimumRows: 1', $form->getAttribute('x-data'));
        $this->assertSame('', $form->getAttribute('x-init'));
    }

    public function test_frontend_module_implements_one_row_minimum_and_trailing_blank_rules(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $source = file_get_contents(resource_path('js/historical-manual.js'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('window.historicalBlankLine', $html);
        foreach (['blankLine()', 'seedLines(raw)', 'padLines()', 'addLine()', 'duplicateLine(index)', 'removeLine(index)'] as $method) {
            $this->assertStringContainsString($method, $source);
        }
        $this->assertStringContainsString('while (rows.length < this.minimumRows)', $source);
        $this->assertStringContainsString('if (!this.isBlank(this.lines[this.lines.length - 1]))', $source);
        $this->assertStringContainsString('this.lines.splice(index, 1)', $source);
        $this->assertStringContainsString('this.padLines()', $source);
    }

    public function test_blank_detection_treats_manual_and_advanced_values_as_meaningful(): void
    {
        $source = file_get_contents(resource_path('js/historical-manual.js'));

        $this->assertIsString($source);
        $this->assertSame(1, preg_match('/isBlank\(line\)\s*\{(?<body>.*?)\n        \},/s', $source, $matches));

        foreach (['line_billable_weight_basis', 'line_metal_value', 'line_making_amount', 'line_wastage_amount', 'line_discount_amount', 'line_taxable', 'line_total'] as $field) {
            $this->assertStringContainsString("'{$field}'", $matches['body'], "{$field} alone must make a row meaningful.");
        }
    }

    // --------------------------------------------------- hydration/order

    public function test_submitted_lines_are_flashed_into_the_edit_forms_old_input_json(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'lines' => [[
                'line_item_name' => 'Archive Gold Ring',
                'line_quantity' => 2,
                'line_net_weight' => 4.25,
                'line_total' => 9876.50,
            ]],
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Archive Gold Ring', $html);
        $form = $this->firstNode($this->xpath($html), '//form[@data-historical-form="manual-preview"]');
        $this->assertStringContainsString('line_item_name', $form->getAttribute('x-data'));
        $this->assertStringContainsString('Archive Gold Ring', $form->getAttribute('x-data'));
    }

    public function test_five_or_more_populated_rows_survive_the_round_trip_without_truncation_and_in_order(): void
    {
        [$owner] = $this->createRetailerTenant();

        // Title Case input on purpose — app\Http\Middleware\NormalizeHumanTextInput
        // title-cases any "*_name" field globally, unrelated to this behavior;
        // matching its output here avoids coupling this test to that middleware.
        $lines = [];
        for ($i = 1; $i <= 5; $i++) {
            $lines[] = ['line_item_name' => "Row {$i} Item", 'line_total' => $i * 100];
        }

        $html = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload(['lines' => $lines]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Item lines <span class="text-sm font-normal text-slate-500">(5)</span>', $html);

        $positions = [];
        foreach ($lines as $line) {
            $pos = strpos($html, $line['line_item_name']);
            $this->assertNotFalse($pos, "Missing flashed row: {$line['line_item_name']}");
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Flashed rows must keep the order they were submitted in.');
    }

    public function test_numeric_zero_values_are_not_treated_as_blank_and_survive_the_round_trip(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'lines' => [[
                // Every field but quantity is empty — a lone "0" must still
                // count as meaningful, same rule as StoreManualHistoricalRequest::lines().
                'line_quantity' => 0,
            ]],
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Item lines <span class="text-sm font-normal text-slate-500">(1)</span>', $html);
    }

    // ------------------------------------------------- blank-row filtering

    public function test_preview_item_count_reflects_only_meaningful_rows_not_padding_blanks(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'lines' => [
                ['line_item_name' => 'Real Item', 'line_total' => 500],
                ['line_item_name' => '', 'line_total' => '', 'line_calculation_enabled' => '1', 'line_total_mode' => 'auto', 'line_tax_mode' => 'no_gst'],
                ['line_item_name' => '', 'line_total' => '', 'line_calculation_enabled' => '1', 'line_total_mode' => 'auto', 'line_tax_mode' => 'no_gst'],
                ['line_item_name' => '', 'line_total' => '', 'line_calculation_enabled' => '1', 'line_total_mode' => 'auto', 'line_tax_mode' => 'no_gst'],
                ['line_item_name' => '', 'line_total' => ''],
            ],
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Item lines <span class="text-sm font-normal text-slate-500">(1)</span>', $html);
    }

    public function test_store_creates_exactly_one_line_from_one_populated_row_among_padding_blanks(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload([
            'original_document_number' => 'PADROW-0001',
            'lines' => [
                ['line_item_name' => 'Real Item', 'line_total' => 500],
                ['line_item_name' => '', 'line_total' => ''],
                ['line_item_name' => '', 'line_total' => ''],
                ['line_item_name' => '', 'line_total' => ''],
            ],
        ]));

        $response->assertRedirect();

        TenantContext::runFor($shop->id, function (): void {
            $document = HistoricalSalesDocument::query()->where('original_document_number', 'PADROW-0001')->firstOrFail();
            $this->assertSame(
                1,
                HistoricalSalesLine::query()->where('historical_sales_document_id', $document->id)->count(),
                'Padding blanks must never persist as sales lines.'
            );
        });
    }

    public function test_blank_padding_rows_never_change_computed_totals(): void
    {
        [$owner] = $this->createRetailerTenant();

        $withoutPadding = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'original_document_number' => 'TOTALS-A',
            'taxable_amount' => 1000,
            'grand_total' => 1180,
            'lines' => [['line_item_name' => 'Item A', 'line_total' => 1180]],
        ]))->assertOk()->getContent();

        $withPadding = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'original_document_number' => 'TOTALS-B',
            'taxable_amount' => 1000,
            'grand_total' => 1180,
            'lines' => [
                ['line_item_name' => 'Item A', 'line_total' => 1180],
                ['line_item_name' => '', 'line_total' => ''],
                ['line_item_name' => '', 'line_total' => ''],
                ['line_item_name' => '', 'line_total' => ''],
            ],
        ]))->assertOk()->getContent();

        $extract = function (string $html): string {
            preg_match('/data-historical-preview-field="grand-total"[^>]*>.*?<dd[^>]*>([\d,.]+)<\/dd>/s', $html, $m);

            return $m[1] ?? '';
        };

        $this->assertNotSame('', $extract($withoutPadding));
        $this->assertSame($extract($withoutPadding), $extract($withPadding), 'Trailing blank padding rows must not change the computed grand total.');
    }

    // --------------------------------------------------- interaction hook

    /**
     * libxml's legacy (non-HTML5) DOMDocument parser mangles "@"/":"-prefixed
     * Alpine attribute names (confirmed: it silently drops the value and
     * lowercases what's left into a bare boolean attribute), so — same as
     * HistoricalMobileUiTest's own `@click="lines.push({})"` check — these
     * assert against the raw response body, not a DOMXPath attribute lookup.
     */
    public function test_each_responsive_surface_carries_one_bubbled_listener_not_one_per_field(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '@input.debounce.250ms="lineChanged(i, $event)"'));
        $this->assertStringContainsString('data-historical-item-grid-desktop', $html);
        $this->assertStringContainsString('data-historical-item-grid-mobile', $html);
    }

    public function test_remove_line_buttons_use_the_padding_aware_component_method(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '@click="removeLine(i)"'));
    }

    public function test_add_item_buttons_use_the_component_method(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '@click="addLine()"'));
    }

    /**
     * This is the one item in the behavior contract PHPUnit structurally
     * cannot prove: that typing into the last rendered row actually grows
     * the register in a live browser. The prior tests prove every piece the
     * behavior is built from (the listener, the pad function, the four-row
     * minimum, the trailing-blank rule) is wired and shipped correctly; only
     * running real Alpine reactivity in a browser proves they fire together.
     * Left for Codex's browser-based UI verification pass.
     */
    public function test_typing_triggered_auto_expansion_is_a_browser_only_contract(): void
    {
        $this->markTestSkipped(
            'Auto-expansion on keystroke requires live Alpine reactivity — not executable under PHPUnit. '
            .'See the render-contract tests above for the server-provable half of this behavior; '
            .'the interaction itself needs Codex browser verification.'
        );
    }
}

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
 * Register-style manual item rows: the create/preview forms start with a
 * padded set of blank rows and auto-expand as the operator types, so nobody
 * has to click "Add line" before every row. Codex owns the table's visual
 * design (frozen separately by HistoricalMobileUiTest); this file proves
 * only the behavior layered on top of it — seeding, padding, blank-row
 * filtering, and the single interaction hook that drives auto-expansion.
 *
 * PHPUnit never runs Alpine. Every test below either (a) asserts the exact
 * server-rendered HTML/attribute contract the client-side JS depends on, or
 * (b) asserts the shipped JS source implements the padding/blank-detection
 * rules byte-for-byte. Neither proves "typing in the browser grows a row" —
 * that is flagged explicitly in test_typing_triggered_auto_expansion_is_a_browser_only_contract().
 */
class HistoricalDefaultItemRowsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, mixed> a valid manual-entry payload. */
    private function manualPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'ROW-' . fake()->unique()->numberBetween(1, 99999),
            'document_date'            => '2023-06-15',
            'source_system'            => 'Manual',
            'customer_name'            => 'Walk-in Customer',
            'grand_total'              => 18000,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
        ], $override);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
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

    public function test_manual_create_form_wires_seed_and_pad_over_the_frozen_x_data_literal(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html  = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $form  = $this->firstNode($this->xpath($html), '//form[@data-historical-form="manual"]');

        // Frozen by HistoricalMobileUiTest — must stay this exact literal.
        $this->assertSame('{ lines: [] }', $form->getAttribute('x-data'));
        // Seeding/padding is layered on via x-init instead of changing x-data.
        // @js(old('lines', [])) compiles to the literal `[]` when there is no
        // old input (Illuminate\Support\Js short-circuits empty arrays/objects).
        $this->assertSame(
            'lines = historicalPadLines(historicalSeedLines([]))',
            $form->getAttribute('x-init')
        );
    }

    public function test_manual_preview_edit_form_shares_the_identical_init_expression(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload())
            ->assertOk()->getContent();
        $form = $this->firstNode($this->xpath($html), '//form[@data-historical-form="manual-preview"]');

        $this->assertSame('{ lines: [] }', $form->getAttribute('x-data'));
        $this->assertSame(
            'lines = historicalPadLines(historicalSeedLines([]))',
            $form->getAttribute('x-init')
        );
    }

    public function test_helper_script_ships_once_and_implements_the_four_row_minimum_and_trailing_blank_rules(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'window.historicalBlankLine'), 'Helper script must render exactly once (@once).');
        foreach (['historicalBlankLine', 'historicalLineIsBlank', 'historicalSeedLines', 'historicalPadLines', 'historicalRemoveLine'] as $fn) {
            $this->assertStringContainsString("window.{$fn}", $html);
        }

        // The four-row minimum and "pad while the last row is still blank"
        // rules, proven present in the shipped source (not executed here).
        $this->assertStringContainsString('next.length < 4', $html);
        $this->assertStringContainsString('historicalLineIsBlank(next[next.length - 1])', $html);
        // Remove stays padding-aware: splice, then re-run the same pad rule.
        $this->assertStringContainsString('next.splice(index, 1)', $html);
        $this->assertStringContainsString('return historicalPadLines(next)', $html);
    }

    // --------------------------------------------------- hydration/order

    public function test_submitted_lines_are_flashed_into_the_edit_forms_old_input_json(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'lines' => [[
                'line_item_name' => 'Archive Gold Ring',
                'line_quantity'  => 2,
                'line_net_weight' => 4.25,
                'line_total'     => 9876.50,
            ]],
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Archive Gold Ring', $html);
        // @js(old('lines', [])) compiles to JSON.parse('...') with \u0022 in
        // place of literal quotes — proves the submitted row actually reached
        // the edit form's init expression, not just the read-only preview.
        $this->assertStringContainsString(
            'x-init="lines = historicalPadLines(historicalSeedLines(JSON.parse(\'[{\u0022line_item_name\u0022:\u0022Archive Gold Ring\u0022',
            $html
        );
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
                ['line_item_name' => '', 'line_total' => ''],
                ['line_item_name' => '', 'line_total' => ''],
                ['line_item_name' => '', 'line_total' => ''],
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
            'grand_total'    => 1180,
            'lines'          => [['line_item_name' => 'Item A', 'line_total' => 1180]],
        ]))->assertOk()->getContent();

        $withPadding = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'original_document_number' => 'TOTALS-B',
            'taxable_amount' => 1000,
            'grand_total'    => 1180,
            'lines'          => [
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
    public function test_row_container_carries_a_single_bubbled_debounced_listener_not_one_per_field(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        $this->assertSame(
            1,
            substr_count($html, '@input.debounce.400ms="lines = historicalPadLines(lines)"'),
            'Auto-expansion must be one bubbled listener, not one per field.'
        );
        $this->assertStringContainsString(
            '<div class="p-4 sm:p-6" @input.debounce.400ms="lines = historicalPadLines(lines)">',
            $html,
            'The listener belongs on the row container div, not an individual input.'
        );
    }

    public function test_remove_line_button_uses_the_padding_aware_remove_helper(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        $this->assertStringContainsString('@click="lines = historicalRemoveLine(lines, i)"', $html);
    }

    public function test_add_line_fallback_button_keeps_the_literal_click_expression_the_mobile_ui_test_pins(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        // Not our contract to change — confirms our edits left it byte-identical.
        $this->assertStringContainsString('@click="lines.push({})"', $html);
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
            . 'See the render-contract tests above for the server-provable half of this behavior; '
            . 'the interaction itself needs Codex browser verification.'
        );
    }
}

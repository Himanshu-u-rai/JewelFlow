<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Services\Historical\HistoricalManualCalculationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The compatibility boundary around `line_rate_basis`, referenced by
 * `HistoricalManualCalculationService::rateBasisFor()`.
 *
 * A MISSING key is a compatibility fallback — it is NOT evidence that a
 * payload is old. Any caller can omit it, and nothing in the record
 * distinguishes an old stored line from a new one that simply left the field
 * out. So the fallback is chosen for the property that matters: it must never
 * move money. `pure_reference` reproduces the pre-change arithmetic byte for
 * byte; defaulting to `as_printed` would restate every affected gold figure
 * upward by 24/purity with nothing in the record explaining it.
 *
 * The three paths pinned here:
 *   1. explicit `as_printed` survives preview → save → reload;
 *   2. an omitted key keeps the previous `pure_reference` reading;
 *   3. an unrecognised value is REJECTED at the HTTP gate, and falls back
 *      (never throws, never prices at 1.0) for non-HTTP callers.
 *
 * Plus the two invariants the basis must not disturb: fine weight always uses
 * true purity, and a manual override outranks either reading.
 */
class HistoricalRateBasisBoundaryTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /**
     * The operator's own sample bill: 22K gold, net 15 g, rate ₹6,200/g.
     *
     *   as_printed     → 15 × 6200 × 1.0   = ₹93,000.00 (the printed rate
     *                                        already embeds the 22K purity)
     *   pure_reference → 15 × 6200 × 22/24 = ₹85,250.00
     *
     * A ₹7,750 gap on one line — which is exactly why the fallback direction
     * cannot be chosen casually.
     */
    private const AS_PRINTED_METAL = 93000.0;

    private const PURE_REFERENCE_METAL = 85250.0;

    private function bill(array $lineOverride = [], array $override = []): array
    {
        $line = array_merge([
            'line_item_name' => 'Gold Earrings',
            'line_quantity' => 2,
            'line_metal_type' => 'gold',
            'line_purity_value' => 22,
            'line_gross_weight' => 16,
            'line_stone_weight' => 1,
            'line_net_weight' => 15,
            'line_rate' => 6200,
            'line_rate_basis' => HistoricalSalesLine::RATE_BASIS_AS_PRINTED,
            'line_billable_weight_basis' => HistoricalSalesLine::BILLABLE_WEIGHT_NET,
            'line_total' => 93000,
        ], $lineOverride);

        return array_merge([
            'original_document_number' => 'BASIS-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => now()->subYear()->toDateString(),
            'source_system' => 'Manual QA',
            'customer_name' => 'Rate Basis QA Customer',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed' => '1',
            'grand_total' => 93000,
            'lines' => [$line],
        ], $override);
    }

    /** The metal_value state as the preview screen actually receives it. */
    private function previewMetalState(array $payload, $owner): array
    {
        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload)->assertOk();

        return $response->viewData('lines')[0]['calculation_state']['metal_value'];
    }

    // --- Path 1: explicit as_printed, end to end ------------------------

    public function test_a_new_manual_entry_keeps_as_printed_through_preview_save_and_reload(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $state = $this->previewMetalState($this->bill(), $owner);

        $this->assertSame(HistoricalSalesLine::RATE_BASIS_AS_PRINTED, $state['inputs']['rate_basis']);
        $this->assertSame(self::AS_PRINTED_METAL, $state['value'],
            'A rate printed at the jewellery own purity must not be scaled by 22/24 a second time.');

        $this->actingAs($owner)
            ->post(route('historical.manual.store'), $this->bill([], ['intent' => 'draft']))
            ->assertRedirect();

        // Reload from the database, not from the response — the round trip
        // through jsonb is the part that could silently drop the key.
        TenantContext::runFor($shop->id, function (): void {
            $line = HistoricalSalesLine::query()->sole();
            $stored = $line->calculation_state['metal_value'];

            $this->assertSame(HistoricalSalesLine::RATE_BASIS_AS_PRINTED, $stored['inputs']['rate_basis'],
                'The stored line no longer says which reading produced its figure.');
            $this->assertSame(self::AS_PRINTED_METAL, (float) $stored['value']);
        });
    }

    // --- Path 2: omitted key keeps the previous reading -----------------

    public function test_a_payload_that_omits_the_basis_keeps_the_previous_pure_reference_reading(): void
    {
        [$owner] = $this->createRetailerTenant();

        $payload = $this->bill();
        unset($payload['lines'][0]['line_rate_basis']);

        $state = $this->previewMetalState($payload, $owner);

        $this->assertSame(HistoricalSalesLine::RATE_BASIS_PURE_REFERENCE, $state['inputs']['rate_basis']);
        $this->assertSame(self::PURE_REFERENCE_METAL, $state['value'],
            'The omission fallback moved money — every stored gold figure would be restated.');
    }

    /**
     * The fallback is a *compatibility* choice, not an age claim. Guard it by
     * value: whatever else changes, the omitted-key figure must equal what the
     * pre-change code produced, i.e. the explicit pure_reference figure.
     */
    public function test_the_omission_fallback_is_identical_to_an_explicit_pure_reference_line(): void
    {
        [$owner] = $this->createRetailerTenant();

        $omitted = $this->bill();
        unset($omitted['lines'][0]['line_rate_basis']);

        $explicit = $this->bill(['line_rate_basis' => HistoricalSalesLine::RATE_BASIS_PURE_REFERENCE]);

        $this->assertSame(
            $this->previewMetalState($explicit, $owner)['value'],
            $this->previewMetalState($omitted, $owner)['value'],
        );
    }

    // --- Path 3: an unrecognised value ----------------------------------

    public function test_an_unrecognised_basis_is_rejected_at_the_http_gate(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $this->bill(['line_rate_basis' => 'whatever_the_client_sent'], ['intent' => 'draft']))
            ->assertRedirect(route('historical.manual.create'))
            ->assertSessionHasErrors('lines.0.line_rate_basis');

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count(),
                'A line with an unknown rate basis must not be persisted at all.');
        });
    }

    /**
     * The service clause is the SECOND line of defence, for callers that never
     * pass through the form request (imports, replays). It must fall back the
     * same safe direction rather than throwing or pricing at 1.0.
     */
    public function test_a_non_http_caller_with_an_unrecognised_basis_falls_back_to_pure_reference(): void
    {
        $prepared = (new HistoricalManualCalculationService)->prepare([], [[
            'line_metal_type' => 'gold',
            'line_purity_value' => 22,
            'line_net_weight' => 15,
            'line_rate' => 6200,
            'line_rate_basis' => 'not_a_basis',
            'line_billable_weight_basis' => HistoricalSalesLine::BILLABLE_WEIGHT_NET,
        ]]);

        $state = $prepared['line_attributes'][0]['calculation_state']['metal_value'];

        $this->assertSame(HistoricalSalesLine::RATE_BASIS_PURE_REFERENCE, $state['inputs']['rate_basis']);
        $this->assertSame(self::PURE_REFERENCE_METAL, $state['value']);
    }

    // --- The two invariants the basis must not disturb -------------------

    /**
     * Fine weight is metal accounting, not pricing: 15 g at 22K is 13.75 g fine
     * whichever way the rate was printed. It is computed and displayed
     * client-side only (`line.line_fine_weight`), so there is no server figure
     * to assert — pin the source instead.
     *
     * ponytail: source assertion, not a JS unit test. This repo ships no JS
     * runner and one invariant does not justify adding vitest + a DOM shim.
     * Add one when there is a second JS behaviour worth testing.
     */
    public function test_fine_weight_uses_true_purity_while_only_the_price_multiplier_follows_the_basis(): void
    {
        $js = file_get_contents(resource_path('js/historical-manual.js'));

        $this->assertStringContainsString(
            "const multiplier = this.fineMultiplier(line.line_metal_type, purity);",
            $js
        );
        $this->assertStringContainsString(
            "line.line_fine_weight = weight === null || multiplier === null ? '' : this.format(weight * multiplier, 3);",
            $js,
            'Fine weight must stay on the true-purity multiplier — never the basis-dependent price multiplier.'
        );
        $this->assertStringContainsString(
            "const priceMultiplier = line.line_rate_basis === 'pure_reference' ? multiplier : 1;",
            $js,
            'Only the price multiplier may follow the rate basis.'
        );
    }

    /**
     * A basis is a suggestion input. An operator who typed the metal value off
     * the paper bill outranks both readings — the suggestion still moves with
     * the basis, but the value does not.
     */
    public function test_a_manual_metal_value_override_outranks_either_basis(): void
    {
        [$owner] = $this->createRetailerTenant();

        $state = $this->previewMetalState($this->bill([
            'line_metal_value' => 91234.56,
            'line_metal_value_mode' => 'manual',
        ]), $owner);

        $this->assertSame('manual', $state['mode']);
        $this->assertSame(91234.56, $state['value'], 'The typed figure was overwritten by a suggestion.');
        $this->assertSame(self::AS_PRINTED_METAL, $state['suggestion'],
            'The suggestion must still track the declared basis so the operator can see what was overridden.');
    }
}

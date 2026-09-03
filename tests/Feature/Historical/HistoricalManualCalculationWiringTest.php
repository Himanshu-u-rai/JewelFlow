<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3, Phase 1 (Section A) — HISTORICAL-BATCH-3-UX-CONTRACT-V2 §5/§9/§10
 * wired into the manual entry HTTP path.
 *
 * Every test here proves the server, never the client, is the source of
 * truth for a calculated field:
 *   - the suggestion is always freshly derived from raw scalar inputs, never
 *     accepted verbatim from the request;
 *   - a claimed `auto` mode is only honoured when the submitted value
 *     actually matches the fresh suggestion, otherwise it is corrected to
 *     `manual`;
 *   - a genuine manual override survives an unrelated dependency changing;
 *   - an explicit "recalculate" always restores `auto`, discarding whatever
 *     manual value rode along with it;
 *   - a metal line with no purity (or no resolvable billable weight) is a
 *     blocking validation error, never a silently favourable default.
 *
 * The flagship fields exercised are `billable_weight` (a real column) and
 * `metal_value` (calculation_state-only per the corrective migration's
 * docblock) — deliberately not the entire §5 dependency graph. This is a
 * scope decision, not an oversight: it demonstrates the full auto/manual
 * wiring mechanics end-to-end on the two fields the contract calls out as
 * having no default, while leaving every other formula (making, wastage,
 * tax, grand total) exactly as the existing normalizer already computes it
 * — so bulk import and every already-green manual test stay byte-identical.
 */
class HistoricalManualCalculationWiringTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** A gold line whose metal_value = 9.5g × ₹6000/g × (22/24) = ₹52,250.00 exactly. */
    private function calcPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'CALC-' . fake()->unique()->numberBetween(1, 999999),
            'document_date'            => now()->toDateString(),
            'source_system'            => 'Manual QA',
            'customer_name'            => 'Calc QA Customer',
            'grand_total'              => 52250,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed'       => '1',
            'lines'                    => [[
                'line_item_name'             => 'Gold Ring',
                'line_quantity'              => 1,
                'line_gross_weight'          => 10,
                'line_net_weight'            => 9.5,
                'line_rate'                  => 6000,
                'line_metal_type'            => 'gold',
                'line_purity_value'          => 22,
                'line_billable_weight_basis' => HistoricalSalesLine::BILLABLE_WEIGHT_NET,
                'line_total'                 => 52250,
            ]],
        ], $override);
    }

    public function test_metal_value_is_always_freshly_computed_from_raw_inputs_not_trusted_from_the_client(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->calcPayload());
        $response->assertOk();

        $state = $response->viewData('lines')[0]['calculation_state']['metal_value'];
        $this->assertSame('auto', $state['mode']);
        $this->assertSame(52250.0, $state['value']);

        // No "value"/"mode" field is touched at all — only a raw input (the
        // rate) changes. The recomputed figure must move with it, proving the
        // value is derived fresh from inputs every request, never cached or
        // trusted from anything the client echoes back.
        $payload = $this->calcPayload();
        $payload['lines'][0]['line_rate'] = 6200;

        $response2 = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $response2->assertOk();

        $state2 = $response2->viewData('lines')[0]['calculation_state']['metal_value'];
        $this->assertSame(round(9.5 * 6200 * 22 / 24, 2), $state2['value']);
    }

    public function test_a_forged_auto_claim_for_metal_value_that_disagrees_with_the_server_suggestion_is_corrected_to_manual(): void
    {
        [$owner] = $this->createRetailerTenant();

        $payload                                      = $this->calcPayload();
        $payload['lines'][0]['line_metal_value']      = 999999; // forged
        $payload['lines'][0]['line_metal_value_mode'] = 'auto';

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $response->assertOk();

        $state = $response->viewData('lines')[0]['calculation_state']['metal_value'];

        $this->assertSame(
            'manual',
            $state['mode'],
            'A claimed-auto value that disagrees with the server suggestion must be corrected to manual, never silently trusted.'
        );
        $this->assertSame(999999.0, $state['value']);
        $this->assertSame(
            52250.0,
            $state['suggestion'],
            'The server-computed suggestion must still be the real one, unaffected by the forged claim.'
        );
    }

    public function test_a_manual_metal_value_override_survives_a_billable_weight_dependency_change(): void
    {
        [$owner] = $this->createRetailerTenant();

        $payload                                      = $this->calcPayload();
        $payload['lines'][0]['line_metal_value']      = 40000;
        $payload['lines'][0]['line_metal_value_mode'] = 'manual';

        $first = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $first->assertOk();
        $firstState = $first->viewData('lines')[0]['calculation_state']['metal_value'];
        $this->assertSame('manual', $firstState['mode']);
        $this->assertSame(40000.0, $firstState['value']);

        // Net weight (a dependency of metal_value via billable_weight) changes;
        // the manual override is resubmitted unchanged, exactly as a real
        // "edit the weight, leave the manual box alone" form submission would.
        $payload['lines'][0]['line_net_weight'] = 12.0;

        $second      = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $second->assertOk();
        $secondState = $second->viewData('lines')[0]['calculation_state']['metal_value'];

        $this->assertSame('manual', $secondState['mode']);
        $this->assertSame(40000.0, $secondState['value'], 'A manual override must survive a dependency change untouched.');
        $this->assertSame(
            round(12.0 * 6000 * 22 / 24, 2),
            $secondState['suggestion'],
            'The tracked suggestion must still update to the fresh figure so "Use automatic value" has something current to offer.'
        );
    }

    public function test_explicit_recalculate_restores_auto_and_discards_the_manual_value(): void
    {
        [$owner] = $this->createRetailerTenant();

        $payload                                            = $this->calcPayload();
        $payload['lines'][0]['line_metal_value']            = 40000;
        $payload['lines'][0]['line_metal_value_mode']        = 'manual';
        $payload['lines'][0]['line_metal_value_recalculate'] = '1';

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $response->assertOk();

        $state = $response->viewData('lines')[0]['calculation_state']['metal_value'];

        $this->assertSame('auto', $state['mode']);
        $this->assertSame(
            52250.0,
            $state['value'],
            'Explicit recalculate must restore the fresh server suggestion, discarding whatever manual value rode along with it.'
        );
    }

    public function test_missing_purity_on_a_gold_line_is_an_actionable_validation_error_not_a_silent_default(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->calcPayload();
        unset($payload['lines'][0]['line_purity_value']);
        $payload['intent'] = 'draft';

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertSessionHas('error');

        $messages = collect($response->getSession()->get('historical_messages', []));
        $this->assertTrue(
            $messages->contains(fn ($m) => ($m['code'] ?? null) === 'metal_value_undetermined'),
            'A gold line with no purity must raise an actionable blocking error, never silently price as 24K/999 pure.'
        );

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(
                0,
                HistoricalSalesDocument::query()->count(),
                'A blocking calculation error must not persist a half-built document.'
            );
        });
    }

    public function test_missing_billable_weight_input_is_an_actionable_validation_error_not_a_silent_default(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->calcPayload();
        // basis = net, but net weight itself is missing — must never silently
        // fall back to gross weight or any other figure.
        unset($payload['lines'][0]['line_net_weight']);
        $payload['intent'] = 'draft';

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertSessionHas('error');

        $messages = collect($response->getSession()->get('historical_messages', []));
        $this->assertTrue(
            $messages->contains(fn ($m) => ($m['code'] ?? null) === 'billable_weight_undetermined'),
            'A missing billable-weight input on a chosen basis must raise an actionable blocking error.'
        );

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
        });
    }
}

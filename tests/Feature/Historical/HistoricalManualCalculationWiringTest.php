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
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** A gold line whose metal_value = 9.5g × ₹6000/g × (22/24) = ₹52,250.00 exactly. */
    private function calcPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'CALC-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'customer_name' => 'Calc QA Customer',
            'grand_total' => 52250,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed' => '1',
            'lines' => [[
                'line_item_name' => 'Gold Ring',
                'line_quantity' => 1,
                'line_gross_weight' => 10,
                'line_net_weight' => 9.5,
                'line_rate' => 6000,
                'line_metal_type' => 'gold',
                'line_purity_value' => 22,
                'line_billable_weight_basis' => HistoricalSalesLine::BILLABLE_WEIGHT_NET,
                'line_total' => 52250,
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

        $payload = $this->calcPayload();
        $payload['lines'][0]['line_metal_value'] = 999999; // forged
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

        $payload = $this->calcPayload();
        $payload['lines'][0]['line_metal_value'] = 40000;
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

        $second = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
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

        $payload = $this->calcPayload();
        $payload['lines'][0]['line_metal_value'] = 40000;
        $payload['lines'][0]['line_metal_value_mode'] = 'manual';
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

    /**
     * Batch 5 — one ordinary bill-level tax treatment (owner's acceptance:
     * "I enter the original information once... I do not enter the same tax
     * amount again elsewhere"). Header-only entry (no lines): the printed
     * example is "for 10000 before tax at 3%, a confirmed CGST/SGST split
     * automatically produces 150 + 150 and a 10300 total".
     */
    public function test_bill_level_gst_rate_auto_splits_cgst_sgst_and_grand_total_on_a_header_only_bill(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'original_document_number' => 'BILLTAX-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'customer_name' => 'Bill Tax QA Customer',
            'taxable_amount' => 10000,
            'bill_gst_rate' => 3,
            'tax_split_type' => HistoricalSalesDocument::TAX_SPLIT_CGST_SGST,
            // Required field; a header-only bill has nothing to auto-derive it
            // from until the server responds — the operator's placeholder is
            // superseded by the fresh auto suggestion below. Real form pages
            // always post an explicit `_mode` hidden input (default "auto") for
            // every calculated document field — mirrored here for the same
            // reason.
            'grand_total' => 10000,
            'tax_total_mode' => 'auto',
            'grand_total_mode' => 'auto',
        ]);
        $response->assertOk();

        $state = $response->viewData('attributes')['calculation_state'];
        $this->assertSame('auto', $state['cgst']['mode']);
        $this->assertSame(150.0, $state['cgst']['value']);
        $this->assertSame(150.0, $state['sgst']['value']);
        $this->assertSame(0.0, $state['igst']['value']);
        $this->assertSame(300.0, $state['tax_total']['value']);
        $this->assertSame(10300.0, $state['grand_total']['value']);
    }

    /** Same rate, IGST split this time — the whole amount lands on igst, none on cgst/sgst. */
    public function test_bill_level_gst_rate_applies_the_igst_split_when_chosen(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'original_document_number' => 'BILLTAX-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'customer_name' => 'Bill Tax QA Customer',
            'taxable_amount' => 10000,
            'bill_gst_rate' => 3,
            'tax_split_type' => HistoricalSalesDocument::TAX_SPLIT_IGST,
            'grand_total' => 10000,
            'tax_total_mode' => 'auto',
            'grand_total_mode' => 'auto',
        ]);
        $response->assertOk();

        $state = $response->viewData('attributes')['calculation_state'];
        $this->assertSame(0.0, $state['cgst']['value']);
        $this->assertSame(0.0, $state['sgst']['value']);
        $this->assertSame(300.0, $state['igst']['value']);
        $this->assertSame(10300.0, $state['grand_total']['value']);
    }

    /** Same bill-level rate, but driven off an ordinary (non-exception) item line instead of a typed header total. */
    public function test_bill_level_gst_rate_applies_on_top_of_an_ordinary_calculated_line(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'original_document_number' => 'BILLTAX-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'customer_name' => 'Bill Tax QA Customer',
            'bill_gst_rate' => 3,
            'tax_split_type' => HistoricalSalesDocument::TAX_SPLIT_CGST_SGST,
            'grand_total' => 1000,
            'tax_total_mode' => 'auto',
            'grand_total_mode' => 'auto',
            'lines' => [[
                'line_item_name' => 'Plain charge line',
                'line_calculation_enabled' => '1',
                'line_other_charge' => 1000,
            ]],
        ]);
        $response->assertOk();

        $state = $response->viewData('attributes')['calculation_state'];
        $this->assertSame(15.0, $state['cgst']['value']);
        $this->assertSame(15.0, $state['sgst']['value']);
        $this->assertSame(30.0, $state['tax_total']['value']);
        $this->assertSame(1030.0, $state['grand_total']['value']);
    }

    /**
     * The "preserve optional item-specific exceptions" half of requirement #1:
     * a line that already manages its own tax (an explicit line_tax_mode) is
     * excluded from the bill-level base, so the ordinary bill-level rate never
     * taxes it a second time.
     */
    public function test_bill_level_gst_rate_excludes_a_line_with_its_own_line_tax_mode(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'original_document_number' => 'BILLTAX-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'customer_name' => 'Bill Tax QA Customer',
            'bill_gst_rate' => 3,
            'tax_split_type' => HistoricalSalesDocument::TAX_SPLIT_CGST_SGST,
            'grand_total' => 1050,
            'tax_total_mode' => 'auto',
            'grand_total_mode' => 'auto',
            'lines' => [[
                'line_item_name' => 'Self-taxed exception line',
                'line_calculation_enabled' => '1',
                'line_other_charge' => 1000,
                'line_tax_mode' => 'gst_exclusive',
                'line_gst_rate' => 5,
            ]],
        ]);
        $response->assertOk();

        $state = $response->viewData('attributes')['calculation_state'];
        $this->assertSame(
            0.0,
            $state['cgst']['value'],
            'A line managing its own tax must not also be taxed by the bill-level rate.'
        );
        $this->assertSame(0.0, $state['sgst']['value']);
        $this->assertSame(
            50.0,
            $state['tax_total']['value'],
            'tax_total must still carry the exception line\'s own 5% tax (50), untouched by the bill-level rate.'
        );
        $this->assertSame(1050.0, $state['grand_total']['value']);
    }

    /** A blank bill_gst_rate means "no bill-level tax" — cgst/sgst/igst stay exactly what the operator typed (or blank). */
    public function test_a_blank_bill_gst_rate_leaves_tax_components_untouched(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'original_document_number' => 'BILLTAX-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'customer_name' => 'Bill Tax QA Customer',
            'taxable_amount' => 10000,
            'grand_total' => 10000,
        ]);
        $response->assertOk();

        // Header-only + no bill-level tax means nothing ever populates
        // document_attributes at all (no calculated line, no bill tax state) —
        // the key itself is legitimately absent, not just empty.
        $state = $response->viewData('attributes')['calculation_state'] ?? [];
        $this->assertArrayNotHasKey(
            'cgst',
            $state,
            'With no bill_gst_rate, applyBillLevelTax() must be a no-op — nothing forces a cgst/sgst/igst state to exist.'
        );
    }

    /**
     * Batch 5 usability correction — "Making charges" is the standard wording
     * and manual entry never asks for a label or category. This proves the
     * internal defaults (HistoricalMakingCharge::DEFAULT_LABEL/CATEGORY_MAKING)
     * actually land in the database on both the document header and the line,
     * for a payload that submits no making_label/making_category/
     * line_making_label at all — not just that the request succeeds.
     */
    public function test_an_ordinary_manual_bill_persists_the_standard_making_charges_wording_with_no_label_input(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // calcPayload()'s line totals ₹52,250 (metal only); adding a ₹500
        // fixed-line making charge on top means both the line total and the
        // printed grand total must move to ₹52,750, or the normalizer's own
        // total-mismatch guard blocks the save — nothing to do with labels.
        $payload = $this->calcPayload(['grand_total' => 52750]);
        $payload['intent'] = 'draft';
        $payload['lines'][0]['line_calculation_enabled'] = '1';
        $payload['lines'][0]['line_making_basis'] = 'fixed_line';
        $payload['lines'][0]['line_making_value'] = '500';
        $payload['lines'][0]['line_making_amount_mode'] = 'auto';
        $payload['lines'][0]['line_total'] = 52750;

        $preview = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $preview->assertOk();
        $this->assertSame('Making charges', $preview->viewData('attributes')['making_label_original'] ?? null);
        $this->assertSame('making', $preview->viewData('attributes')['making_category'] ?? null);
        $this->assertSame('Making charges', $preview->viewData('lines')[0]['making_label_original'] ?? null);
        $this->assertSame('making', $preview->viewData('lines')[0]['making_category'] ?? null);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertSessionHasNoErrors();

        TenantContext::runFor($shop->id, function () use ($payload): void {
            $document = HistoricalSalesDocument::query()
                ->where('original_document_number', $payload['original_document_number'])
                ->firstOrFail();
            $this->assertSame('Making charges', $document->making_label_original);
            $this->assertSame('making', $document->making_category);

            $line = $document->lines()->firstOrFail();
            $this->assertSame('Making charges', $line->making_label_original);
            $this->assertSame('making', $line->making_category);
            $this->assertSame(500.0, (float) $line->making_amount);
        });
    }

    /**
     * Same auto/manual state machine as metal_value (resolveState() is
     * field-name-agnostic), exercised on `making_amount` specifically because
     * this batch's own label/wording change touches the making pathway
     * directly — a manual making override must survive an unrelated metal
     * rate change exactly like a manual metal_value override already does.
     */
    public function test_a_manual_making_amount_override_survives_a_metal_value_dependency_change_and_explicit_recalculate_restores_auto(): void
    {
        [$owner] = $this->createRetailerTenant();

        $payload = $this->calcPayload(['grand_total' => 60000]);
        $payload['lines'][0]['line_calculation_enabled'] = '1';
        $payload['lines'][0]['line_making_basis'] = 'percent';
        $payload['lines'][0]['line_making_value'] = '10'; // auto suggestion: 10% of 52250 = 5225
        $payload['lines'][0]['line_making_amount'] = 7000; // manual override
        $payload['lines'][0]['line_making_amount_mode'] = 'manual';
        $payload['lines'][0]['line_total'] = 60000;

        $first = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $first->assertOk();
        $firstState = $first->viewData('lines')[0]['calculation_state']['making_amount'];
        $this->assertSame('manual', $firstState['mode']);
        $this->assertSame(7000.0, $firstState['value']);

        // The rate moves (a metal_value dependency, hence a making_amount
        // dependency under the percent basis) while the manual making figure
        // is resubmitted unchanged.
        $payload['lines'][0]['line_rate'] = 6200;

        $second = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $second->assertOk();
        $secondState = $second->viewData('lines')[0]['calculation_state']['making_amount'];
        $this->assertSame('manual', $secondState['mode']);
        $this->assertSame(7000.0, $secondState['value'], 'A manual making_amount override must survive a metal_value dependency change untouched.');
        $this->assertSame(
            round(round(9.5 * 6200 * 22 / 24, 2) * 10 / 100, 2),
            $secondState['suggestion'],
            'The tracked suggestion must still refresh so "Use automatic value" has a current figure.'
        );

        // Explicit recalculate on the same field restores auto and discards
        // the manual figure, exactly like metal_value's own recalculate test.
        $payload['lines'][0]['line_making_amount_recalculate'] = '1';

        $third = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $third->assertOk();
        $thirdState = $third->viewData('lines')[0]['calculation_state']['making_amount'];
        $this->assertSame('auto', $thirdState['mode']);
        $this->assertSame($secondState['suggestion'], $thirdState['value']);
    }

    /**
     * Requirement #1 "preserved manual values": `applyBillLevelTax()` calls
     * `resolveState()` a second time on `tax_total`/`grand_total` after the
     * line-total loop already ran once. The method's own docblock claims a
     * manual value survives that second call untouched — this proves the
     * claim rather than trusting the comment.
     */
    public function test_a_manual_grand_total_override_survives_the_bill_level_tax_pass(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), [
            'original_document_number' => 'BILLTAX-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'customer_name' => 'Bill Tax QA Customer',
            'taxable_amount' => 10000,
            'bill_gst_rate' => 3,
            'tax_split_type' => HistoricalSalesDocument::TAX_SPLIT_CGST_SGST,
            // Operator-typed grand_total disagrees with both the plain 10000
            // taxable base AND the bill-tax-inclusive 10300 — a genuine
            // manual figure, not a coincidental match with either suggestion.
            'grand_total' => 10500,
            'grand_total_mode' => 'manual',
            'tax_total_mode' => 'auto',
        ]);
        $response->assertOk();

        $state = $response->viewData('attributes')['calculation_state'];
        $this->assertSame('manual', $state['grand_total']['mode']);
        $this->assertSame(10500.0, $state['grand_total']['value'], 'A manual grand_total must survive applyBillLevelTax()\'s second resolveState() call.');
        // cgst/sgst/tax_total stay auto-derived from the tax base regardless —
        // only grand_total itself was claimed manual.
        $this->assertSame('auto', $state['cgst']['mode']);
        $this->assertSame(150.0, $state['cgst']['value']);
        $this->assertSame(300.0, $state['tax_total']['value']);
    }
}

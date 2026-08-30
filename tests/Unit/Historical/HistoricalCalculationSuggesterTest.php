<?php

namespace Tests\Unit\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Services\Historical\HistoricalCalculationSuggester;
use App\Support\Historical\HistoricalMakingCharge;
use Tests\TestCase;

/**
 * Pure formula-graph coverage for HISTORICAL-BATCH-3-UX-CONTRACT-V2 §5, plus
 * the §13 isolation proof (no today's-rate/GST/offer/wastage default ever
 * leaks into a suggestion) and the §4 "never silently assume net weight" rule.
 */
class HistoricalCalculationSuggesterTest extends TestCase
{
    private HistoricalCalculationSuggester $suggester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suggester = new HistoricalCalculationSuggester();
    }

    // ------------------------------------------------------------ metal value

    public function test_gold_metal_value_uses_karat_fine_weight_multiplier(): void
    {
        // 10g billable, rate 6000/g, 22K gold => multiplier 22/24.
        $value = $this->suggester->suggestMetalValue('gold', 22.0, 10.0, 6000.0);

        $this->assertSame(round(10.0 * 6000.0 * (22 / 24), 2), $value);
    }

    public function test_silver_metal_value_uses_millesimal_fine_weight_multiplier(): void
    {
        $value = $this->suggester->suggestMetalValue('silver', 925.0, 100.0, 80.0);

        $this->assertSame(round(100.0 * 80.0 * (925 / 1000), 2), $value);
    }

    public function test_platinum_metal_value_has_no_multiplier_applied(): void
    {
        // Platinum's purity is a hallmark spec, not accounting truth — no multiplier.
        $value = $this->suggester->suggestMetalValue('platinum', 950.0, 10.0, 3000.0);

        $this->assertSame(30000.0, $value);
    }

    public function test_fully_custom_free_text_metal_has_no_multiplier_applied(): void
    {
        $value = $this->suggester->suggestMetalValue('antique brass', 0.0, 10.0, 500.0);

        $this->assertSame(5000.0, $value);
    }

    public function test_metal_value_is_null_when_billable_weight_missing(): void
    {
        $this->assertNull($this->suggester->suggestMetalValue('gold', 22.0, null, 6000.0));
    }

    public function test_metal_value_is_null_when_rate_missing(): void
    {
        $this->assertNull($this->suggester->suggestMetalValue('gold', 22.0, 10.0, null));
    }

    // ------------------------------------------------------- billable weight

    public function test_billable_weight_basis_gross_takes_gross_weight(): void
    {
        $this->assertSame(
            12.5,
            $this->suggester->suggestBillableWeight(HistoricalSalesLine::BILLABLE_WEIGHT_GROSS, 12.5, 10.0)
        );
    }

    public function test_billable_weight_basis_net_takes_net_weight(): void
    {
        $this->assertSame(
            10.0,
            $this->suggester->suggestBillableWeight(HistoricalSalesLine::BILLABLE_WEIGHT_NET, 12.5, 10.0)
        );
    }

    public function test_billable_weight_basis_manual_never_auto_populates(): void
    {
        $this->assertNull(
            $this->suggester->suggestBillableWeight(HistoricalSalesLine::BILLABLE_WEIGHT_MANUAL, 12.5, 10.0)
        );
    }

    public function test_billable_weight_never_silently_defaults_to_net_for_an_unrecognised_basis(): void
    {
        // Correcting the V1 bug: an absent/invalid basis must not fall back to
        // net weight, even though net weight IS available here.
        $this->assertNull($this->suggester->suggestBillableWeight('', 12.5, 10.0));
    }

    // ------------------------------------------------------------ making amount

    public function test_making_per_item_multiplies_by_quantity(): void
    {
        $amount = $this->suggester->suggestMakingAmount(
            HistoricalMakingCharge::BASIS_PER_ITEM, 500.0, null, null, 3.0
        );
        $this->assertSame(1500.0, $amount);
    }

    public function test_making_per_gram_multiplies_by_net_weight_never_gross(): void
    {
        $amount = $this->suggester->suggestMakingAmount(
            HistoricalMakingCharge::BASIS_PER_GRAM, 450.0, null, 9.5, null
        );
        $this->assertSame(round(450.0 * 9.5, 2), $amount);
    }

    public function test_making_percent_is_of_metal_value(): void
    {
        $amount = $this->suggester->suggestMakingAmount(
            HistoricalMakingCharge::BASIS_PERCENT, 12.0, 55000.0, null, null
        );
        $this->assertSame(round(55000.0 * 12 / 100, 2), $amount);
    }

    public function test_making_fixed_invoice_and_fixed_line_are_flat(): void
    {
        $this->assertSame(2000.0, $this->suggester->suggestMakingAmount(
            HistoricalMakingCharge::BASIS_FIXED_INVOICE, 2000.0, null, null, null
        ));
        $this->assertSame(750.0, $this->suggester->suggestMakingAmount(
            HistoricalMakingCharge::BASIS_FIXED_LINE, 750.0, null, null, null
        ));
    }

    public function test_making_included_and_informational_contribute_nothing_further(): void
    {
        $this->assertSame(0.0, $this->suggester->suggestMakingAmount(
            HistoricalMakingCharge::BASIS_INCLUDED, 500.0, null, null, null
        ));
        $this->assertSame(0.0, $this->suggester->suggestMakingAmount(
            HistoricalMakingCharge::BASIS_INFORMATIONAL, 500.0, null, null, null
        ));
    }

    public function test_making_unknown_basis_is_never_guessed(): void
    {
        $this->assertNull($this->suggester->suggestMakingAmount(
            HistoricalMakingCharge::BASIS_UNKNOWN, 500.0, null, null, null
        ));
    }

    public function test_making_per_item_is_null_when_quantity_missing(): void
    {
        $this->assertNull($this->suggester->suggestMakingAmount(
            HistoricalMakingCharge::BASIS_PER_ITEM, 500.0, null, null, null
        ));
    }

    // ------------------------------------------------------------------ wastage

    public function test_wastage_percent_is_of_metal_value(): void
    {
        $amount = $this->suggester->suggestWastageAmount(
            HistoricalSalesLine::WASTAGE_BASIS_PERCENT, 5.0, 55000.0
        );
        $this->assertSame(round(55000.0 * 5 / 100, 2), $amount);
    }

    public function test_wastage_flat_is_a_flat_amount(): void
    {
        $this->assertSame(300.0, $this->suggester->suggestWastageAmount(
            HistoricalSalesLine::WASTAGE_BASIS_FLAT, 300.0, 55000.0
        ));
    }

    public function test_wastage_never_defaults_when_basis_missing(): void
    {
        // Locked decision: historical wastage defaults blank, never auto-applies
        // today's shop wastage setting or any implicit basis.
        $this->assertNull($this->suggester->suggestWastageAmount(null, 5.0, 55000.0));
    }

    // -------------------------------------------------------------- stone value

    public function test_stone_value_from_weight_times_rate(): void
    {
        $this->assertSame(5000.0, $this->suggester->suggestStoneValue(2.0, 2500.0, null));
    }

    public function test_stone_value_flat_entry_wins_over_weight_times_rate(): void
    {
        $this->assertSame(4500.0, $this->suggester->suggestStoneValue(2.0, 2500.0, 4500.0));
    }

    // --------------------------------------------------------------- line total

    public function test_line_charges_sum_hallmark_rhodium_other_treating_missing_as_zero(): void
    {
        $this->assertSame(150.0, $this->suggester->suggestLineCharges(100.0, null, 50.0));
    }

    public function test_line_subtotal_sums_all_components_treating_missing_as_zero(): void
    {
        $subtotal = $this->suggester->suggestLineSubtotal(55000.0, 2000.0, null, 5000.0, 150.0);
        $this->assertSame(62150.0, $subtotal);
    }

    public function test_discount_amount_percent_is_clamped_to_base(): void
    {
        $this->assertSame(1000.0, $this->suggester->discountAmount(1000.0, 'percent', 200.0));
    }

    public function test_discount_amount_fixed_is_clamped_to_base(): void
    {
        $this->assertSame(500.0, $this->suggester->discountAmount(500.0, 'fixed', 999999.0));
    }

    public function test_discount_amount_is_zero_when_no_discount_configured(): void
    {
        $this->assertSame(0.0, $this->suggester->discountAmount(1000.0, null, null));
    }

    public function test_taxable_value_split_no_gst(): void
    {
        $split = $this->suggester->taxableValueSplit(1000.0, 'no_gst', 3.0);
        $this->assertSame(['taxable' => 1000.0, 'gst' => 0.0, 'total' => 1000.0], $split);
    }

    public function test_taxable_value_split_gst_exclusive_adds_gst_on_top(): void
    {
        $split = $this->suggester->taxableValueSplit(1000.0, 'gst_exclusive', 3.0);
        $this->assertSame(1000.0, $split['taxable']);
        $this->assertSame(30.0, $split['gst']);
        $this->assertSame(1030.0, $split['total']);
    }

    public function test_taxable_value_split_gst_inclusive_extracts_gst_from_total(): void
    {
        $split = $this->suggester->taxableValueSplit(1030.0, 'gst_inclusive', 3.0);
        $this->assertSame(1000.0, $split['taxable']);
        $this->assertSame(30.0, $split['gst']);
        $this->assertSame(1030.0, $split['total']);
    }

    // --------------------------------------------------------------- bill level

    public function test_bill_subtotal_sums_line_totals(): void
    {
        $this->assertSame(3000.0, $this->suggester->suggestBillSubtotal([1000.0, 2000.0]));
    }

    public function test_taxable_value_subtracts_bill_discount_and_frozen_offer_snapshot(): void
    {
        $this->assertSame(2700.0, $this->suggester->suggestTaxableValue(3000.0, 200.0, 100.0));
    }

    public function test_tax_split_cgst_sgst_is_a_mechanical_fifty_fifty(): void
    {
        $split = $this->suggester->suggestTaxSplit(1000.0, 3.0, HistoricalSalesDocument::TAX_SPLIT_CGST_SGST);
        $this->assertSame(['cgst' => 15.0, 'sgst' => 15.0, 'igst' => 0.0], $split);
    }

    public function test_tax_split_igst_is_the_full_amount(): void
    {
        $split = $this->suggester->suggestTaxSplit(1000.0, 3.0, HistoricalSalesDocument::TAX_SPLIT_IGST);
        $this->assertSame(['cgst' => 0.0, 'sgst' => 0.0, 'igst' => 30.0], $split);
    }

    public function test_grand_total_adds_taxable_tax_cess_and_operator_round_off(): void
    {
        $total = $this->suggester->suggestGrandTotal(1000.0, 15.0, 15.0, 0.0, 5.0, -0.03);
        $this->assertSame(1034.97, $total);
    }

    // --------------------------------------------------------------- settlement

    public function test_outstanding_is_never_negative_when_overpaid(): void
    {
        $this->assertSame(0.0, $this->suggester->suggestOutstanding(1000.0, 1200.0));
    }

    public function test_outstanding_is_the_shortfall_when_underpaid(): void
    {
        $this->assertSame(200.0, $this->suggester->suggestOutstanding(1000.0, 800.0));
    }

    public function test_advance_credit_is_zero_when_not_overpaid(): void
    {
        $this->assertSame(0.0, $this->suggester->suggestAdvanceCredit(1000.0, 800.0));
    }

    public function test_advance_credit_captures_the_overpaid_excess(): void
    {
        $this->assertSame(200.0, $this->suggester->suggestAdvanceCredit(1000.0, 1200.0));
    }
}

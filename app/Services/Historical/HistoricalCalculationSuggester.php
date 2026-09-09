<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Services\MetalRegistry;
use App\Support\Historical\HistoricalMakingCharge;

/**
 * Pure formula-graph implementation for HISTORICAL-BATCH-3-UX-CONTRACT-V2 §5.
 *
 * STATELESS. No DB reads/writes, no `now()`, no `config()`, no shop-setting
 * lookups. Every value returned is a SUGGESTION derived only from the scalar
 * inputs handed in — the caller (a not-yet-written draft controller/service)
 * is responsible for deciding whether to apply it, per the auto/manual state
 * machine in `HistoricalCalculationStateService`.
 *
 * Never reaches for "today's rate", "today's GST%", "today's offer" or "the
 * shop's current wastage default" — every one of those is an explicit,
 * operator-supplied argument here, never an implicit default. This is the
 * isolation the contract's §13/red-first-matrix isolation proof requires.
 *
 * REUSE MAP (see class docblocks already on disk for the originals):
 *  - Metal value:      MetalRegistry::fineWeightMultiplier() for gold/silver.
 *                       Tier 2/3/custom metals get NO multiplier at all
 *                       (billable_weight IS the billable quantity already) —
 *                       `fineWeightMultiplier()` would throw for Tier 3, so it
 *                       is only ever called once `MetalRegistry::isSupported()`
 *                       has confirmed the metal is Tier 1/2.
 *  - Making charge:     `HistoricalMakingChargeNormalizer`'s exact per-basis
 *                       math (per_item: value×qty; per_gram: value×net_weight,
 *                       NEVER gross; percent: value×metal_value/100;
 *                       fixed_invoice/fixed_line: flat; included/informational:
 *                       contribute nothing further to the subtotal — the value
 *                       is either already inside another figure or purely a
 *                       note; unknown: null, never guessed).
 *  - Discount/taxable:  `QuickBillService::discountAmount()` and its 3-mode
 *                       (no_gst / gst_inclusive / gst_exclusive) taxable-value
 *                       formula, lifted verbatim (both are pure/stateless).
 */
class HistoricalCalculationSuggester
{
    // ------------------------------------------------------------ line level

    /**
     * `metal_value = billable_weight × rate_per_gram × fine_multiplier(metal, purity)`
     * per §4. Returns null when any required input is missing — never
     * fabricates a value from an incomplete line. This includes a missing
     * purity on a `purity_accounting` Tier 1 metal (gold/silver): a null
     * multiplier there means the value genuinely cannot be suggested, not
     * that it should be priced as though 24K/999 pure (foundation-audit D2).
     *
     * §4 RECONCILIATION — the fine multiplier belongs there only when the rate
     * is a 24K/999 REFERENCE rate. That is the live QuickBill convention (its
     * field is labelled "Rate (pure 24K/999)") and §4 was written against it,
     * but a historical paper bill usually prints the rate for the jewellery's
     * own purity. Applying the multiplier to an already-22K rate discounts it
     * twice: the supplied invoice (15g billable, 22K, ₹6,200/g, ₹6,200 stone)
     * printed ₹99,200 while the reference reading produced ₹91,450 — ₹7,750
     * short, exactly the 22/24 gap on the metal leg.
     *
     * The basis is therefore an explicit input, not an assumption.
     * AS_PRINTED uses a multiplier of 1.0 (the rate already carries purity);
     * PURE_REFERENCE is the unchanged §4 path, same arithmetic as before.
     * Purity stays REQUIRED in both: an unspecified purity on gold/silver
     * still returns null, because purity remains accounting truth for
     * fine-weight bookkeeping even when it does not scale the price.
     *
     * Default is AS_PRINTED so plain invoice copying needs no extra decision.
     * Callers replaying STORED rows must pass PURE_REFERENCE explicitly when
     * the persisted calculation_state predates this key — see
     * HistoricalManualCalculationService::rateBasisFor().
     */
    public function suggestMetalValue(
        ?string $metal,
        ?float $purity,
        ?float $billableWeight,
        ?float $ratePerGram,
        string $rateBasis = HistoricalSalesLine::RATE_BASIS_AS_PRINTED,
    ): ?float {
        if ($billableWeight === null || $ratePerGram === null || $billableWeight < 0 || $ratePerGram < 0) {
            return null;
        }

        $multiplier = $this->fineMultiplier($metal, $purity);

        if ($multiplier === null) {
            return null;
        }

        // A rate printed at the jewellery's own purity already embeds it.
        // Purity was still validated above — only its price effect is dropped.
        if ($rateBasis === HistoricalSalesLine::RATE_BASIS_AS_PRINTED) {
            $multiplier = 1.0;
        }

        return round($billableWeight * $ratePerGram * $multiplier, 2);
    }

    /**
     * The exact "no multiplier for non-accounting metals" rule from §11/§16:
     * gold/silver use `MetalRegistry::fineWeightMultiplier()`; every other
     * metal (Tier 2, Tier 3, free-text/custom) passes billable weight through
     * unscaled (multiplier 1.0) because `fineWeightMultiplier()` either
     * returns null (platinum/copper — purity isn't accounting truth) or would
     * throw outright for a metal the registry has never heard of.
     *
     * Foundation-audit D2 correction: for a Tier 1 `purity_accounting` metal
     * (gold, silver — `MetalRegistry::purityIsAccountingTruth()`), a MISSING
     * purity must return null, never `1.0`. Returning `1.0` there would
     * silently price an unspecified-purity line as if it were 24K/999 pure —
     * exactly the class of "missing operator decision resolved by a silent
     * favourable default" the whole calculation-contract exists to prevent.
     * The `1.0` passthrough is preserved unchanged for every other case:
     * Tier 2 (`purity_spec`, e.g. platinum), Tier 3/manual-grade (e.g.
     * copper), and free-text/unsupported metals — none of those have an
     * accounting-truth purity to be missing in the first place. This method
     * never consults current item/shop settings to fill a missing purity —
     * it stays a pure function, exactly like the rest of this class.
     */
    private function fineMultiplier(?string $metal, ?float $purity): ?float
    {
        if ($metal === null || trim($metal) === '') {
            return 1.0;
        }

        if (! MetalRegistry::isSupported($metal)) {
            return 1.0;
        }

        if ($purity === null) {
            return MetalRegistry::purityIsAccountingTruth($metal) ? null : 1.0;
        }

        $multiplier = MetalRegistry::fineWeightMultiplier($metal, $purity);

        return $multiplier ?? 1.0;
    }

    /**
     * `billable_weight` auto-population per §4: `gross`/`net` bases mirror the
     * corresponding weight field; `manual` has no auto value at all (the
     * operator's direct entry IS the value, there is nothing to suggest).
     * Returns null for `manual` and for an unrecognised basis — the caller
     * must never fall back to net weight by default (the corrected V1 bug).
     */
    public function suggestBillableWeight(
        string $basis,
        ?float $grossWeight,
        ?float $netWeight,
    ): ?float {
        return match ($basis) {
            HistoricalSalesLine::BILLABLE_WEIGHT_GROSS => $grossWeight,
            HistoricalSalesLine::BILLABLE_WEIGHT_NET => $netWeight,
            default => null,
        };
    }

    /**
     * Full 8-basis vocabulary (`HistoricalMakingCharge::BASES`), exact math
     * lifted from `HistoricalMakingChargeNormalizer`. `included`/
     * `informational` return 0.0 for the SUBTOTAL CONTRIBUTION specifically
     * (the declared value, if any, is a display-only fact the caller already
     * has — this method answers "how much does this add to line_subtotal").
     */
    public function suggestMakingAmount(
        string $basis,
        ?float $value,
        ?float $metalValue,
        ?float $netWeight,
        ?float $quantity,
    ): ?float {
        if ($value === null) {
            return null;
        }

        return match ($basis) {
            HistoricalMakingCharge::BASIS_FIXED_INVOICE,
            HistoricalMakingCharge::BASIS_FIXED_LINE => round($value, 2),

            HistoricalMakingCharge::BASIS_PER_ITEM => $quantity === null
                ? null
                : round($value * $quantity, 2),

            // Net weight, never gross — making is charged on the metal that
            // was worked, matching HistoricalMakingChargeNormalizer exactly.
            HistoricalMakingCharge::BASIS_PER_GRAM => $netWeight === null
                ? null
                : round($value * $netWeight, 2),

            HistoricalMakingCharge::BASIS_PERCENT => $metalValue === null
                ? null
                : round($value * $metalValue / 100, 2),

            // Already inside another figure (included) or purely a printed
            // note (informational) — neither adds anything further here.
            HistoricalMakingCharge::BASIS_INCLUDED,
            HistoricalMakingCharge::BASIS_INFORMATIONAL => 0.0,

            // BASIS_UNKNOWN or anything unrecognised: never guessed.
            default => null,
        };
    }

    /**
     * `wastage_amount = f(wastage_basis, wastage_pct/amount, metal_value)`.
     * `percent` is a percentage of `metal_value` (QuickBill's pre-tax
     * convention); `flat` is the value as-is (PricingEngine's fine-weight×rate
     * convention collapses to a flat rupee amount once computed, so from this
     * service's point of view a flat wastage figure IS just a flat amount).
     * Both live formulas stay available as an explicit operator choice per
     * §6 — neither is silently preferred.
     */
    public function suggestWastageAmount(
        ?string $basis,
        ?float $value,
        ?float $metalValue,
    ): ?float {
        if ($basis === null || $value === null) {
            return null;
        }

        return match ($basis) {
            HistoricalSalesLine::WASTAGE_BASIS_PERCENT => $metalValue === null
                ? null
                : round($value * $metalValue / 100, 2),
            HistoricalSalesLine::WASTAGE_BASIS_FLAT => round($value, 2),
            default => null,
        };
    }

    /** `stone_value = stone_weight × stone_rate` (or a flat entered value). */
    public function suggestStoneValue(
        ?float $stoneWeight,
        ?float $stoneRate,
        ?float $flatValue,
    ): ?float {
        if ($flatValue !== null) {
            return round($flatValue, 2);
        }

        if ($stoneWeight === null || $stoneRate === null) {
            return null;
        }

        return round($stoneWeight * $stoneRate, 2);
    }

    /** `line_charges = hallmark + rhodium + other`. Missing charges count as 0. */
    public function suggestLineCharges(
        ?float $hallmarkCharge,
        ?float $rhodiumCharge,
        ?float $otherCharge,
    ): float {
        return round(($hallmarkCharge ?? 0) + ($rhodiumCharge ?? 0) + ($otherCharge ?? 0), 2);
    }

    /**
     * `line_subtotal = metal_value + making_amount + wastage_amount +
     * stone_value + line_charges`. Any missing component counts as 0 — a
     * header-only or partially-mapped line still produces a usable subtotal
     * from whatever IS known.
     */
    public function suggestLineSubtotal(
        ?float $metalValue,
        ?float $makingAmount,
        ?float $wastageAmount,
        ?float $stoneValue,
        float $lineCharges,
    ): float {
        return round(
            ($metalValue ?? 0) + ($makingAmount ?? 0) + ($wastageAmount ?? 0) + ($stoneValue ?? 0) + $lineCharges,
            2
        );
    }

    /**
     * `line_discount_amt = f(line_discount_type, line_discount_value,
     * line_subtotal)` — exact clamp formula lifted from
     * `QuickBillService::discountAmount()`.
     */
    public function discountAmount(float $base, ?string $type, ?float $value): float
    {
        if ($base <= 0 || $type === null || $value === null || $value <= 0) {
            return 0.0;
        }

        $discount = $type === 'percent'
            ? round($base * ($value / 100), 2)
            : round($value, 2);

        return round(min($base, max(0, $discount)), 2);
    }

    /**
     * `line_taxable = line_subtotal − line_discount_amt`, then
     * `line_total = per taxable-value mode`, the exact 3-mode formula from
     * `QuickBillService::persist()` lifted verbatim. `$taxMode` is one of
     * `no_gst`, `gst_inclusive`, `gst_exclusive`.
     *
     * @return array{taxable: float, gst: float, total: float}
     */
    public function taxableValueSplit(float $afterDiscount, string $taxMode, float $gstRate): array
    {
        if ($taxMode === 'gst_inclusive') {
            $divisor = 1 + ($gstRate / 100);
            $taxable = $divisor > 0 ? round($afterDiscount / $divisor, 2) : $afterDiscount;
            $gst = round($afterDiscount - $taxable, 2);

            return ['taxable' => $taxable, 'gst' => $gst, 'total' => round($afterDiscount, 2)];
        }

        if ($taxMode === 'gst_exclusive') {
            $taxable = $afterDiscount;
            $gst = round($taxable * ($gstRate / 100), 2);

            return ['taxable' => $taxable, 'gst' => $gst, 'total' => round($taxable + $gst, 2)];
        }

        // no_gst
        return ['taxable' => $afterDiscount, 'gst' => 0.0, 'total' => round($afterDiscount, 2)];
    }

    // ------------------------------------------------------------ bill level

    /** `bill_subtotal = Σ line_total`. */
    public function suggestBillSubtotal(array $lineTotals): float
    {
        return round(array_sum($lineTotals), 2);
    }

    /**
     * `taxable_value = bill_subtotal − bill_discount_amt − offer_discount_amt`.
     * `$offerDiscountAmt` is always a frozen snapshot per §6 — this method
     * never re-derives it, only subtracts what it is given.
     */
    public function suggestTaxableValue(
        float $billSubtotal,
        float $billDiscountAmt,
        float $offerDiscountAmt,
    ): float {
        return round(max(0, $billSubtotal - $billDiscountAmt - $offerDiscountAmt), 2);
    }

    /**
     * `cgst/sgst OR igst = taxable_value × operator-entered GST%` — mechanical
     * 50/50 (cgst_sgst) or full (igst) split, no interstate place-of-supply
     * logic, matching live's actual behaviour per §6/§13.
     *
     * @return array{cgst: float, sgst: float, igst: float}
     */
    public function suggestTaxSplit(float $taxableValue, float $gstRate, string $splitType): array
    {
        $totalGst = round($taxableValue * ($gstRate / 100), 2);

        if ($splitType === HistoricalSalesDocument::TAX_SPLIT_IGST) {
            return ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => $totalGst];
        }

        $half = round($totalGst / 2, 2);

        return ['cgst' => $half, 'sgst' => round($totalGst - $half, 2), 'igst' => 0.0];
    }

    /**
     * `grand_total = taxable_value + tax_amounts + cess_amount + round_off`.
     * `$roundOff` is always the operator-typed flat value (QuickBillService's
     * pattern) — never PricingEngine's live-rounding-preference formula.
     */
    public function suggestGrandTotal(
        float $taxableValue,
        float $cgst,
        float $sgst,
        float $igst,
        float $cess,
        float $roundOff,
    ): float {
        return round($taxableValue + $cgst + $sgst + $igst + $cess + $roundOff, 2);
    }

    // -------------------------------------------------------- settlement

    /**
     * `outstanding = max(grand_total − paid_total, 0)` per §5/§16 (corrected
     * from V1's negative-capable formula — `historical_docs_non_negative_check`
     * makes negative outstanding physically impossible).
     */
    public function suggestOutstanding(float $grandTotal, float $paidTotal): float
    {
        return round(max(0, $grandTotal - $paidTotal), 2);
    }

    /**
     * `advance_credit = max(paid_total − grand_total, 0)` — a Historical-only,
     * record-only figure. Never posts to `StoreCreditMovement` or any live
     * customer/store credit balance; this method only computes the number.
     */
    public function suggestAdvanceCredit(float $grandTotal, float $paidTotal): float
    {
        return round(max(0, $paidTotal - $grandTotal), 2);
    }
}

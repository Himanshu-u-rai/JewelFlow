<?php

namespace App\Services\Returns;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ShopPreferences;

/**
 * Single source of truth for "how much should this returned line refund?"
 *
 * Reads the shop's return policy from ShopPreferences, builds the
 * appropriate ValuationBasis, delegates the metal/making/stone math to
 * GoldValuationService, then layers on GST policy and restocking fee.
 *
 * The locked allocations on invoice_items (allocated_discount,
 * allocated_round_off, allocated_loyalty_pts) are always carried forward
 * unchanged — they are invoice-level accounting facts, not subject to
 * per-shop refund policy.
 *
 * Math convention: paisa-integer internally, round(..., 2) at boundaries.
 * No Money object introduced; matches the existing codebase convention.
 */
class RefundPolicyResolver
{
    public function __construct(private GoldValuationService $valuation) {}

    /**
     * Compute the refund breakdown for one returned invoice line.
     *
     * @param  ShopPreferences|null  $policy  null → treat as "refund 100%"
     */
    public function resolve(
        InvoiceItem $line,
        Invoice $invoice,
        ValuationBasis $basis,
        ?ShopPreferences $policy,
    ): RefundBreakdown {
        $lineTotalPaisa    = (int) round((float) $line->line_total    * 100);
        $makingChargesPaisa = (int) round((float) $line->making_charges * 100);
        $stoneAmountPaisa  = (int) round((float) $line->stone_amount  * 100);
        $hallmarkChargesPaisa = (int) round((float) ($line->hallmark_charges ?? 0) * 100);

        // ── Step 1: subtotal after component deductions ──────────────────
        // When the item is available we delegate the computation to
        // GoldValuationService (which applies making/stone/wear deductions
        // from the basis). When item is null we replicate the same three-line
        // logic inline to avoid changing the service's signature.
        if ($line->item) {
            $subtotalPaisa = $this->valuation->valueOfFinishedItemInPaisa(
                $line->item,
                $line,
                now(),
                $basis,
            );
        } else {
            // Inline fallback — identical to GoldValuationService::valueAtSaleDayRate.
            $subtotalPaisa = $lineTotalPaisa;
            if (!$basis->isDeducted('making_charges_refundable')) {
                $subtotalPaisa -= $makingChargesPaisa;
            }
            if (!$basis->isDeducted('stone_charges_refundable')) {
                $subtotalPaisa -= $stoneAmountPaisa;
            }
            if (!$basis->isDeducted('hallmark_charges_refundable')) {
                $subtotalPaisa -= $hallmarkChargesPaisa;
            }
            if ($basis->wearLossPct > 0) {
                $subtotalPaisa = (int) floor($subtotalPaisa * (1 - ($basis->wearLossPct / 100)));
            }
            $subtotalPaisa = max($subtotalPaisa, 0);
        }

        // Track what was retained for the audit breakdown.
        $makingRetainedPaisa = (!$basis->isDeducted('making_charges_refundable'))
            ? $makingChargesPaisa
            : 0;
        $stoneRetainedPaisa = (!$basis->isDeducted('stone_charges_refundable'))
            ? $stoneAmountPaisa
            : 0;
        $hallmarkRetainedPaisa = (!$basis->isDeducted('hallmark_charges_refundable'))
            ? $hallmarkChargesPaisa
            : 0;
        $wearLossAmountPaisa = $lineTotalPaisa - $makingRetainedPaisa - $stoneRetainedPaisa - $hallmarkRetainedPaisa - $subtotalPaisa;
        $wearLossAmountPaisa = max($wearLossAmountPaisa, 0);

        // ── Step 2: restocking fee ────────────────────────────────────────
        $restockingFeePct    = $basis->restockingFeeOverridePct ?? ($policy?->restocking_fee_pct ?? 0.0);
        $restockingFeePct    = (float) $restockingFeePct;
        $restockingFeePaisa  = ($restockingFeePct > 0)
            ? (int) round($subtotalPaisa * ($restockingFeePct / 100))
            : 0;
        $subtotalAfterFee = max($subtotalPaisa - $restockingFeePaisa, 0);

        // ── Step 3: GST ───────────────────────────────────────────────────
        $gstChargedPaisa  = (int) round((float) $line->gst_amount * 100);
        $refundGst        = ($basis->refundGstOverride ?? ($policy?->refund_gst !== false))
            ? round($gstChargedPaisa / 100, 2)
            : 0.0;

        // ── Step 4: invoice-level allocations (policy-independent) ────────
        $refundDiscount  = round((float) $line->allocated_discount,    2);
        $refundRoundOff  = round((float) $line->allocated_round_off,   4);
        $refundLoyalty   = (int) $line->allocated_loyalty_pts;

        // ── Step 5: final total ───────────────────────────────────────────
        $refundSubtotal = round($subtotalAfterFee / 100, 2);
        $refundTotal    = round($refundSubtotal + $refundGst - $refundDiscount + $refundRoundOff, 2);
        $refundTotal    = max($refundTotal, 0.0);

        // ── Audit breakdown ───────────────────────────────────────────────
        $breakdown = [
            'original_line_total'    => round($lineTotalPaisa / 100, 2),
            'making_charges'         => round($makingChargesPaisa / 100, 2),
            'making_retained'        => round($makingRetainedPaisa / 100, 2),
            'stone_amount'           => round($stoneAmountPaisa / 100, 2),
            'stone_retained'         => round($stoneRetainedPaisa / 100, 2),
            'hallmark_charges'       => round($hallmarkChargesPaisa / 100, 2),
            'hallmark_retained'      => round($hallmarkRetainedPaisa / 100, 2),
            'wear_loss_pct'          => $basis->wearLossPct,
            'wear_loss_amount'       => round($wearLossAmountPaisa / 100, 2),
            'restocking_fee_pct'     => $restockingFeePct,
            'restocking_fee_amount'  => round($restockingFeePaisa / 100, 2),
            'gst_charged'            => round($gstChargedPaisa / 100, 2),
            'gst_refunded'           => $refundGst,
            'discount_allocated'     => $refundDiscount,
            'round_off_allocated'    => $refundRoundOff,
            'final_refund_total'     => $refundTotal,
            'policy_at_settle'       => $this->policySnapshot($policy),
        ];

        return new RefundBreakdown(
            refundSubtotal:   $refundSubtotal,
            refundGst:        $refundGst,
            refundDiscount:   $refundDiscount,
            refundRoundOff:   $refundRoundOff,
            refundLoyaltyPts: $refundLoyalty,
            refundTotal:      $refundTotal,
            breakdown:        $breakdown,
        );
    }

    /**
     * Build the canonical ValuationBasis from shop preferences. Safe to call
     * with null policy — returns "refund everything" as default.
     *
     * @param  ?float  $rateOverride  Per-gram rate for fixed_rate / manual_override sources.
     *                                Ignored for sale_day_rate and today_rate.
     */
    public function basisFromPolicy(
        ?ShopPreferences $policy,
        string $rateSource = ValuationBasis::SOURCE_SALE_DAY_RATE,
        ?float $rateOverride = null,
    ): ValuationBasis {
        $deductions  = $policy?->toRefundDeductions() ?? [];
        $wearLossPct = (float) ($policy?->wear_loss_pct ?? 0);

        return match ($rateSource) {
            ValuationBasis::SOURCE_TODAY_RATE      => ValuationBasis::todayRate($deductions, $wearLossPct),
            ValuationBasis::SOURCE_FIXED_RATE      => ValuationBasis::fixedRate($deductions, $wearLossPct, $rateOverride),
            ValuationBasis::SOURCE_MANUAL_OVERRIDE => ValuationBasis::manualOverride($deductions, $wearLossPct, $rateOverride),
            default                                => ValuationBasis::saleDayRate($deductions, $wearLossPct),
        };
    }

    /** Small snapshot of the policy settings in effect at settle-time. */
    private function policySnapshot(?ShopPreferences $policy): array
    {
        if (!$policy) {
            return ['source' => 'none — full refund default'];
        }
        return [
            'refund_making_charges'  => (bool) ($policy->refund_making_charges  ?? true),
            'refund_stone_charges'   => (bool) ($policy->refund_stone_charges   ?? true),
            'refund_hallmark_charges'=> (bool) ($policy->refund_hallmark_charges ?? true),
            'refund_gst'             => (bool) ($policy->refund_gst             ?? true),
            'wear_loss_pct'          => (float) ($policy->wear_loss_pct         ?? 0),
            'restocking_fee_pct'     => (float) ($policy->restocking_fee_pct    ?? 0),
            'configured_at'          => $policy->return_policy_configured_at?->toDateTimeString(),
        ];
    }
}

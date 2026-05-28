<?php

namespace App\Services\Returns;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Centralised valuation logic for returns and exchanges. See architecture §6.
 *
 * Phase 1 only implements SOURCE_SALE_DAY_RATE — i.e. value the piece using
 * the gold rate that was charged on the original invoice. This is enough to
 * back full-invoice refunds (which simply copy the original line totals)
 * and partial returns where the rate hasn't moved.
 *
 * Later phases plug in today's rate (Phase 3 exchanges), fixed-rate
 * (buyback agreements), and manual override (owner-approved adjustments).
 *
 * The service is intentionally narrow — given an item + date + basis, return
 * the Rupee value as a paisa-integer Money object. Callers compose the
 * basis from shop preferences + per-transaction overrides.
 */
class GoldValuationService
{
    /**
     * Computes the credit value of a returned finished item.
     *
     * For Phase 1 the implementation is the simplest legal version: look at
     * the original invoice_item, sum the components the basis says are
     * refundable, and return that amount in paisa.
     *
     * @return int  value in paisa (₹1 = 100 paisa)
     */
    public function valueOfFinishedItemInPaisa(
        Item $item,
        InvoiceItem $originalLine,
        CarbonInterface $valuationDate,
        ValuationBasis $basis,
    ): int {
        return match ($basis->rateSource) {
            ValuationBasis::SOURCE_SALE_DAY_RATE   => $this->valueAtSaleDayRate($originalLine, $basis),
            ValuationBasis::SOURCE_TODAY_RATE      => $this->valueAtTodayRate($item, $originalLine, $basis),
            ValuationBasis::SOURCE_FIXED_RATE,
            ValuationBasis::SOURCE_MANUAL_OVERRIDE => $this->valueAtFixedRate($item, $originalLine, $basis),
            default => throw new LogicException(
                'GoldValuationService: rate source "' . $basis->rateSource . '" is not implemented yet.'
            ),
        };
    }

    /**
     * Sale-day basis: the piece is credited at the amount the customer was
     * originally charged on the invoice line. Deductions (making/stone) only
     * reduce the credit if the shop policy says they're non-refundable.
     */
    private function valueAtSaleDayRate(InvoiceItem $originalLine, ValuationBasis $basis): int
    {
        $linePaisa = (int) round(((float) $originalLine->line_total) * 100);

        if (!$basis->isDeducted('making_charges_refundable')) {
            $linePaisa -= (int) round(((float) $originalLine->making_charges) * 100);
        }
        if (!$basis->isDeducted('stone_charges_refundable')) {
            $linePaisa -= (int) round(((float) $originalLine->stone_amount) * 100);
        }
        if ($basis->wearLossPct > 0) {
            $linePaisa = (int) floor($linePaisa * (1 - ($basis->wearLossPct / 100)));
        }

        return max($linePaisa, 0);
    }

    /**
     * Today's-rate basis: re-value the piece's gold content at today's per-gram
     * rate from `shop_daily_metal_rates`. Stone amount is added back as-is
     * (stones don't have a per-gram market rate). Making/hallmark/rhodium/etc
     * are skipped by default (typically non-refundable when revalued).
     *
     * Used in exchanges where rates have moved since the original sale.
     */
    private function valueAtTodayRate(Item $item, InvoiceItem $originalLine, ValuationBasis $basis): int
    {
        $shop = $item->shop;
        if (!$shop) {
            throw new LogicException('Cannot value item: shop relation missing.');
        }

        $todaysRate = app(\App\Services\ShopPricingService::class)->currentDailyRate($shop);
        if (!$todaysRate) {
            throw new LogicException(
                "Today's daily metal rates are not set for this shop. Save today's rates from the Pricing screen first."
            );
        }

        $metalType = strtolower((string) $item->metal_type);
        $ratePerFineGram = match ($metalType) {
            'gold'   => (float) $todaysRate->gold_24k_rate_per_gram,
            'silver' => (float) $todaysRate->silver_999_rate_per_gram,
            default  => throw new LogicException("Unknown metal type '{$metalType}' for today-rate valuation."),
        };

        // Fine weight via the single authority. Gold → /24, silver → /1000.
        // The match() above already rejects unknown metals, so this is non-null.
        $fineMultiplier = \App\Services\MetalRegistry::fineWeightMultiplier($metalType, (float) $item->purity);
        if ($fineMultiplier === null) {
            throw new LogicException("Cannot derive fine weight for non-accounting metal '{$metalType}'.");
        }
        $fineWeight = (float) $originalLine->weight * $fineMultiplier;

        // Fall back to item's net_metal_weight if the line's weight column is the
        // gross weight (which appears to be the case for some legacy lines).
        if ($fineWeight <= 0 && $item->net_metal_weight > 0) {
            $fineWeight = (float) $item->net_metal_weight * $fineMultiplier;
        }

        $goldValue = $fineWeight * $ratePerFineGram;
        $valuePaisa = (int) round($goldValue * 100);

        // Stones return with the piece — credit their original amount unless
        // the shop's policy says otherwise.
        if ($basis->isDeducted('stone_charges_refundable')) {
            $valuePaisa += (int) round(((float) $originalLine->stone_amount) * 100);
        }

        // Wear / melting-loss percentage.
        if ($basis->wearLossPct > 0) {
            $valuePaisa = (int) floor($valuePaisa * (1 - ($basis->wearLossPct / 100)));
        }

        return max($valuePaisa, 0);
    }

    /**
     * Fixed-rate / manual-override basis: value the piece at the caller-supplied
     * per-gram rate stored in $basis->ratePerGramOverride.
     *
     * Stone charges are included only when the policy says they're refundable
     * (same logic as today-rate). Wear-loss percentage is applied last.
     *
     * Used for:
     *   - SOURCE_FIXED_RATE     — pre-agreed buyback contract rate
     *   - SOURCE_MANUAL_OVERRIDE — owner-approved per-transaction rate
     */
    private function valueAtFixedRate(Item $item, InvoiceItem $originalLine, ValuationBasis $basis): int
    {
        if ($basis->ratePerGramOverride === null || $basis->ratePerGramOverride <= 0) {
            throw new LogicException(
                'Fixed/manual rate override requires a positive ratePerGramOverride value in the ValuationBasis.'
            );
        }

        $metalType    = strtolower((string) $item->metal_type);
        $purityDivisor = $metalType === 'silver' ? 1000.0 : 24.0;
        $fineWeight   = (float) $originalLine->weight * ((float) $item->purity / $purityDivisor);

        // Fall back to item's net_metal_weight if the line weight is gross weight.
        if ($fineWeight <= 0 && $item->net_metal_weight > 0) {
            $fineWeight = (float) $item->net_metal_weight * ((float) $item->purity / $purityDivisor);
        }

        $goldValue   = $fineWeight * $basis->ratePerGramOverride;
        $valuePaisa  = (int) round($goldValue * 100);

        // Stones return with the piece — credit their original amount unless
        // the shop's policy says stone charges are non-refundable.
        if ($basis->isDeducted('stone_charges_refundable')) {
            $valuePaisa += (int) round(((float) $originalLine->stone_amount) * 100);
        }

        // Wear / melting-loss percentage.
        if ($basis->wearLossPct > 0) {
            $valuePaisa = (int) floor($valuePaisa * (1 - ($basis->wearLossPct / 100)));
        }

        return max($valuePaisa, 0);
    }

    /**
     * Convenience: refund-attributable subtotal+gst+discount+round_off for a
     * full-invoice return. Returns an array of paisa-integers ready to fan
     * out across a credit note's columns.
     *
     * @return array{subtotal_paisa:int, gst_paisa:int, discount_paisa:int, round_off_micropaisa:int, total_paisa:int}
     */
    public function refundComponentsForFullInvoice(Invoice $invoice): array
    {
        $subtotal = (int) round(((float) $invoice->subtotal) * 100);
        $gst      = (int) round(((float) $invoice->gst) * 100);
        $discount = (int) round(((float) $invoice->discount) * 100);
        $roundOff = (int) round(((float) $invoice->round_off) * 10000); // 4dp
        $total    = (int) round(((float) $invoice->total) * 100);

        return [
            'subtotal_paisa'        => $subtotal,
            'gst_paisa'             => $gst,
            'discount_paisa'        => $discount,
            'round_off_micropaisa'  => $roundOff,
            'total_paisa'           => $total,
        ];
    }
}

<?php

namespace App\Services\Returns;

/**
 * Immutable DTO describing the rules for valuing a piece at a given date.
 *
 * Returns and exchanges use this to compute "what is this returned piece
 * worth as a credit?" The Phase 1 surface is small — only sale_day_rate is
 * implemented; today_rate / fixed_rate / manual_override are stubs that
 * later phases fill in.
 *
 * Construction goes through a static factory so callers don't accidentally
 * mix sources (e.g. today's rate with a fixed override).
 */
final class ValuationBasis
{
    public const SOURCE_SALE_DAY_RATE    = 'sale_day_rate';
    public const SOURCE_TODAY_RATE       = 'today_rate';        // Phase 3
    public const SOURCE_FIXED_RATE       = 'fixed_rate';        // Phase 3
    public const SOURCE_MANUAL_OVERRIDE  = 'manual_override';   // Phase 3

    /**
     * @param  string  $rateSource
     * @param  ?float  $ratePerGramOverride  used when source = fixed_rate / manual_override
     * @param  array<string, bool>  $deductions  e.g. ['making_charges_refundable' => false]
     * @param  float   $wearLossPct  flat % deduction for melting loss or damage
     */
    private function __construct(
        public readonly string $rateSource,
        public readonly ?float $ratePerGramOverride,
        public readonly array  $deductions,
        public readonly float  $wearLossPct,
        public readonly ?bool  $refundGstOverride = null,
        public readonly ?float $restockingFeeOverridePct = null,
    ) {}

    /**
     * Default Phase 1 basis: value the piece at its sale-day rate, no overrides.
     * Deductions are read from the shop's preferences (caller passes them in).
     */
    public static function saleDayRate(array $deductions = [], float $wearLossPct = 0.0, ?bool $refundGstOverride = null, ?float $restockingFeeOverridePct = null): self
    {
        return new self(
            rateSource: self::SOURCE_SALE_DAY_RATE,
            ratePerGramOverride: null,
            deductions: $deductions,
            wearLossPct: $wearLossPct,
            refundGstOverride: $refundGstOverride,
            restockingFeeOverridePct: $restockingFeeOverridePct,
        );
    }

    /**
     * Phase 3 — value the piece at today's gold rate from shop_daily_metal_rates.
     * Used in exchanges when rates have moved since the original sale.
     */
    public static function todayRate(array $deductions = [], float $wearLossPct = 0.0, ?bool $refundGstOverride = null, ?float $restockingFeeOverridePct = null): self
    {
        return new self(
            rateSource: self::SOURCE_TODAY_RATE,
            ratePerGramOverride: null,
            deductions: $deductions,
            wearLossPct: $wearLossPct,
            refundGstOverride: $refundGstOverride,
            restockingFeeOverridePct: $restockingFeeOverridePct,
        );
    }

    /**
     * Phase D — value the piece at a fixed (pre-agreed) per-gram rate.
     * Used in buyback agreements where the rate is set by contract.
     */
    public static function fixedRate(array $deductions = [], float $wearLossPct = 0.0, ?float $ratePerGram = null, ?bool $refundGstOverride = null, ?float $restockingFeeOverridePct = null): self
    {
        return new self(
            rateSource: self::SOURCE_FIXED_RATE,
            ratePerGramOverride: $ratePerGram,
            deductions: $deductions,
            wearLossPct: $wearLossPct,
            refundGstOverride: $refundGstOverride,
            restockingFeeOverridePct: $restockingFeeOverridePct,
        );
    }

    /**
     * Phase D — value the piece at an owner-approved manual rate override.
     * Requires the exchanges.override_rate permission. Must be within ±20% of
     * today's rate (enforced at the controller layer).
     */
    public static function manualOverride(array $deductions = [], float $wearLossPct = 0.0, ?float $ratePerGram = null, ?bool $refundGstOverride = null, ?float $restockingFeeOverridePct = null): self
    {
        return new self(
            rateSource: self::SOURCE_MANUAL_OVERRIDE,
            ratePerGramOverride: $ratePerGram,
            deductions: $deductions,
            wearLossPct: $wearLossPct,
            refundGstOverride: $refundGstOverride,
            restockingFeeOverridePct: $restockingFeeOverridePct,
        );
    }

    /**
     * Reads a deduction setting; defaults to TRUE (deductions ON) when absent.
     * Shops that haven't configured a policy yet are safer with deductions ON
     * (i.e. refund only the gold value, not making/hallmark/etc.).
     */
    public function isDeducted(string $key): bool
    {
        return $this->deductions[$key] ?? true;
    }
}

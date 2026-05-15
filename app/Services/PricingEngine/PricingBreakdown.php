<?php

namespace App\Services\PricingEngine;

/**
 * Output of PricingEngine::compute().
 *
 * Carries the FULL numeric breakdown plus the canonical JSON form used for
 * HMAC signing. The canonical form:
 *  - has explicit ordered keys (no insertion-order surprises)
 *  - casts every numeric to a fixed-decimal string (no "1.10" vs "1.1")
 *  - is encoded with JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
 *
 * The exact JSON bytes from toCanonicalJson() are what gets hashed. Verifiers
 * MUST hash the stored breakdown_json column directly — never re-serialise
 * from a decoded array.
 */
final class PricingBreakdown
{
    public function __construct(
        public readonly int $shopId,
        public readonly ?int $customerId,
        public readonly string $mode,
        /** @var array<int,array{item_id:int,line_total:float,gst_amount:float,weight:?float,rate:?float,making:?float,stone:?float}> */
        public readonly array $lines,
        public readonly float $subtotal,
        public readonly float $manualDiscount,
        public readonly float $offerDiscount,
        public readonly float $totalDiscount,
        public readonly float $taxable,
        public readonly float $gstRate,
        public readonly float $gst,
        public readonly float $wastageCharge,
        public readonly float $preRoundTotal,
        public readonly float $roundingAdjustment,
        public readonly float $finalTotal,
        public readonly string $roundingMethod,
        public readonly float $roundingNearest,
        public readonly ?array $offer = null,
        public readonly ?array $compliance = null,
        public readonly ?array $customerGold = null,
        public readonly ?string $quoteId = null,
        public readonly ?string $expiresAt = null,
    ) {
    }

    /**
     * Canonical PHP array used for signing.
     *
     * Field order is explicit and frozen. Every numeric is forced to a
     * 2-decimal string (or 4 for rounding-adjustment to support sub-paise
     * precision intermediates). Adding fields in the future MUST append to
     * the bottom — never insert mid-array, or every existing quote breaks
     * verification.
     */
    public function toCanonicalArray(): array
    {
        $lines = array_map(function (array $line): array {
            return [
                'item_id'    => isset($line['item_id']) ? (int) $line['item_id'] : null,
                'line_total' => self::money($line['line_total'] ?? 0.0),
                'gst_amount' => self::money($line['gst_amount'] ?? 0.0),
                'weight'     => isset($line['weight']) ? self::weight($line['weight']) : null,
                'rate'       => isset($line['rate']) ? self::money($line['rate']) : null,
                'making'     => isset($line['making']) ? self::money($line['making']) : null,
                'stone'      => isset($line['stone']) ? self::money($line['stone']) : null,
            ];
        }, $this->lines);

        return [
            'shop_id'              => $this->shopId,
            'customer_id'          => $this->customerId,
            'mode'                 => $this->mode,
            'quote_id'             => $this->quoteId,
            'expires_at'           => $this->expiresAt,
            'subtotal'             => self::money($this->subtotal),
            'manual_discount'      => self::money($this->manualDiscount),
            'offer_discount'       => self::money($this->offerDiscount),
            'total_discount'       => self::money($this->totalDiscount),
            'taxable'              => self::money($this->taxable),
            'gst_rate'             => self::ratePercent($this->gstRate),
            'gst'                  => self::money($this->gst),
            'wastage_charge'       => self::money($this->wastageCharge),
            'pre_round_total'      => self::money($this->preRoundTotal),
            'rounding_adjustment'  => self::adjustment($this->roundingAdjustment),
            'final_total'          => self::money($this->finalTotal),
            'rounding_method'      => $this->roundingMethod,
            'rounding_nearest'     => self::money($this->roundingNearest),
            'offer'                => $this->offer,
            'compliance'           => $this->compliance,
            'customer_gold'        => $this->customerGold,
            'lines'                => $lines,
        ];
    }

    public function toCanonicalJson(): string
    {
        return json_encode(
            $this->toCanonicalArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Display-friendly array for API responses (numbers as numbers, not strings).
     * NEVER use this for signing — use toCanonicalArray()/toCanonicalJson().
     */
    public function toApiArray(): array
    {
        return [
            'shop_id'              => $this->shopId,
            'customer_id'          => $this->customerId,
            'mode'                 => $this->mode,
            'quote_id'             => $this->quoteId,
            'expires_at'           => $this->expiresAt,
            'subtotal'             => round($this->subtotal, 2),
            'manual_discount'      => round($this->manualDiscount, 2),
            'offer_discount'       => round($this->offerDiscount, 2),
            'total_discount'       => round($this->totalDiscount, 2),
            'taxable'              => round($this->taxable, 2),
            'gst_rate'             => round($this->gstRate, 2),
            'gst'                  => round($this->gst, 2),
            'wastage_charge'       => round($this->wastageCharge, 2),
            'pre_round_total'      => round($this->preRoundTotal, 2),
            'rounding_adjustment'  => round($this->roundingAdjustment, 4),
            'final_total'          => round($this->finalTotal, 2),
            'rounding_method'      => $this->roundingMethod,
            'rounding_nearest'     => round($this->roundingNearest, 2),
            'offer'                => $this->offer,
            'compliance'           => $this->compliance,
            'customer_gold'        => $this->customerGold,
            'lines'                => $this->lines,
        ];
    }

    /** Format a money value as a fixed 2-decimal string for canonical serialisation. */
    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** Rounding adjustment may be negative and finer than money (intermediate). */
    private static function adjustment(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    /** Weight as 3-decimal string (grams). */
    private static function weight(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    /** GST rate as 2-decimal percent string. */
    private static function ratePercent(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

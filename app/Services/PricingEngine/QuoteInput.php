<?php

namespace App\Services\PricingEngine;

use InvalidArgumentException;

/**
 * Input contract for PricingEngine.
 *
 * Plain DTO with strict types. Constructed via the named static factories
 * (no fluent setters / no public mutation) so the engine receives a frozen
 * input object whose shape is verified at boundary.
 */
final class QuoteInput
{
    public const MODE_RETAILER     = 'retailer';
    public const MODE_MANUFACTURER = 'manufacturer';

    private function __construct(
        public readonly int $shopId,
        public readonly ?int $customerId,
        public readonly string $mode,
        public readonly array $itemIds = [],
        public readonly float $manualDiscount = 0.0,
        public readonly ?float $manualDiscountPercent = null,
        public readonly ?int $offerSchemeId = null,
        public readonly ?array $schemeRedemption = null,
        public readonly bool $allowAutoOfferFallback = true,
        public readonly ?float $goldRate = null,
        public readonly float $making = 0.0,
        public readonly float $stone = 0.0,
        public readonly float $creditOffset = 0.0,
        public readonly string $client = 'web',
        // MC-2 additive: making-charge mode + raw value. Default FIXED with
        // makingValue mirroring $making → byte-identical to pre-MC behaviour.
        public readonly string $makingType = MakingChargeType::FIXED,
        public readonly ?float $makingValue = null,
    ) {
        if (! in_array($mode, [self::MODE_RETAILER, self::MODE_MANUFACTURER], true)) {
            throw new InvalidArgumentException("QuoteInput mode must be 'retailer' or 'manufacturer'.");
        }
        if (! MakingChargeType::isValid($makingType)) {
            throw new InvalidArgumentException("QuoteInput makingType must be one of: " . implode(', ', MakingChargeType::all()) . '.');
        }
        if ($makingValue !== null && $makingValue < 0) {
            throw new InvalidArgumentException('QuoteInput makingValue must be >= 0.');
        }
        if ($shopId <= 0) {
            throw new InvalidArgumentException('QuoteInput shopId must be a positive integer.');
        }
        if ($manualDiscount < 0) {
            throw new InvalidArgumentException('QuoteInput manualDiscount must be >= 0.');
        }
        if ($manualDiscountPercent !== null && ($manualDiscountPercent < 0 || $manualDiscountPercent > 100)) {
            throw new InvalidArgumentException('QuoteInput manualDiscountPercent must be between 0 and 100.');
        }
        if ($mode === self::MODE_MANUFACTURER && ($goldRate === null || $goldRate <= 0)) {
            throw new InvalidArgumentException('Manufacturer quotes require a positive goldRate.');
        }
        if ($mode === self::MODE_MANUFACTURER && count($itemIds) !== 1) {
            throw new InvalidArgumentException('Manufacturer quotes accept exactly one item.');
        }
        if ($mode === self::MODE_RETAILER && empty($itemIds)) {
            throw new InvalidArgumentException('Retailer quotes require at least one item.');
        }
    }

    public static function retailer(
        int $shopId,
        ?int $customerId,
        array $itemIds,
        float $manualDiscount = 0.0,
        ?float $manualDiscountPercent = null,
        ?int $offerSchemeId = null,
        ?array $schemeRedemption = null,
        bool $allowAutoOfferFallback = true,
        string $client = 'web',
    ): self {
        return new self(
            shopId: $shopId,
            customerId: $customerId,
            mode: self::MODE_RETAILER,
            itemIds: array_values(array_map('intval', $itemIds)),
            manualDiscount: round(max(0.0, $manualDiscount), 2),
            manualDiscountPercent: $manualDiscountPercent,
            offerSchemeId: $offerSchemeId,
            schemeRedemption: $schemeRedemption,
            allowAutoOfferFallback: $allowAutoOfferFallback,
            client: $client,
        );
    }

    public static function manufacturer(
        int $shopId,
        ?int $customerId,
        int $itemId,
        float $goldRate,
        float $making = 0.0,
        float $stone = 0.0,
        float $manualDiscount = 0.0,
        float $creditOffset = 0.0,
        string $client = 'web',
        string $makingType = MakingChargeType::FIXED,
        ?float $makingValue = null,
    ): self {
        // For fixed mode the raw value IS the rupee amount ($making); for
        // percentage/per-gram the caller supplies makingValue (% or ₹/g).
        $resolvedValue = $makingType === MakingChargeType::FIXED
            ? round(max(0.0, $makingValue ?? $making), 2)
            : round(max(0.0, (float) ($makingValue ?? 0.0)), 2);

        return new self(
            shopId: $shopId,
            customerId: $customerId,
            mode: self::MODE_MANUFACTURER,
            itemIds: [(int) $itemId],
            manualDiscount: round(max(0.0, $manualDiscount), 2),
            goldRate: round($goldRate, 2),
            making: round(max(0.0, $making), 2),
            stone: round(max(0.0, $stone), 2),
            creditOffset: round(max(0.0, $creditOffset), 2),
            client: $client,
            makingType: $makingType,
            makingValue: $resolvedValue,
        );
    }

    /**
     * Serialise as the JSON payload to persist in pos_quotes.input_payload.
     * The shape mirrors the named factories — replayable.
     */
    public function toArray(): array
    {
        $base = [
            'shop_id'                   => $this->shopId,
            'customer_id'               => $this->customerId,
            'mode'                      => $this->mode,
            'item_ids'                  => $this->itemIds,
            'manual_discount'           => $this->manualDiscount,
            'manual_discount_percent'   => $this->manualDiscountPercent,
            'offer_scheme_id'           => $this->offerSchemeId,
            'scheme_redemption'         => $this->schemeRedemption,
            'allow_auto_offer_fallback' => $this->allowAutoOfferFallback,
            'client'                    => $this->client,
        ];

        if ($this->mode === self::MODE_MANUFACTURER) {
            $base['gold_rate']     = $this->goldRate;
            $base['making']        = $this->making;
            $base['stone']         = $this->stone;
            $base['credit_offset'] = $this->creditOffset;

            // MC-2: append mode metadata ONLY for non-fixed modes, so fixed-mode
            // input_payload stays byte-identical to pre-MC stored quotes.
            if ($this->makingType !== MakingChargeType::FIXED) {
                $base['making_type']  = $this->makingType;
                $base['making_value'] = $this->makingValue;
            }
        }

        return $base;
    }

    public static function fromArray(array $payload): self
    {
        $mode = $payload['mode'] ?? self::MODE_RETAILER;
        if ($mode === self::MODE_RETAILER) {
            return self::retailer(
                shopId: (int) $payload['shop_id'],
                customerId: isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
                itemIds: (array) ($payload['item_ids'] ?? []),
                manualDiscount: (float) ($payload['manual_discount'] ?? 0),
                manualDiscountPercent: isset($payload['manual_discount_percent']) ? (float) $payload['manual_discount_percent'] : null,
                offerSchemeId: isset($payload['offer_scheme_id']) ? (int) $payload['offer_scheme_id'] : null,
                schemeRedemption: $payload['scheme_redemption'] ?? null,
                allowAutoOfferFallback: (bool) ($payload['allow_auto_offer_fallback'] ?? true),
                client: (string) ($payload['client'] ?? 'web'),
            );
        }

        return self::manufacturer(
            shopId: (int) $payload['shop_id'],
            customerId: isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
            itemId: (int) ($payload['item_ids'][0] ?? 0),
            goldRate: (float) $payload['gold_rate'],
            making: (float) ($payload['making'] ?? 0),
            stone: (float) ($payload['stone'] ?? 0),
            manualDiscount: (float) ($payload['manual_discount'] ?? 0),
            creditOffset: (float) ($payload['credit_offset'] ?? 0),
            client: (string) ($payload['client'] ?? 'web'),
            // MC-2: absent ⇒ fixed (legacy/pre-MC payloads replay identically).
            makingType: MakingChargeType::normalize($payload['making_type'] ?? null),
            makingValue: isset($payload['making_value']) ? (float) $payload['making_value'] : null,
        );
    }
}

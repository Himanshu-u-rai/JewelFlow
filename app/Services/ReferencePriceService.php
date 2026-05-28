<?php

namespace App\Services;

use App\Models\ShopMetalReferencePrice;

/**
 * Class B — reference-price memo (platinum, copper).
 *
 * This service has EXACTLY two operations: record a new reference, and read
 * the latest one. It is intentionally minimal so it cannot grow into a rate
 * engine.
 *
 * STRICTLY FORBIDDEN inside this file (architecture-test enforced from R6):
 *   - importing or calling ShopPricingService
 *   - importing MetalRate
 *   - the tokens: rate_per_gram, resolvedRateForToday, RepriceRetailerInventoryJob,
 *     MetalRate::, shop_daily_metal_rate
 *
 * If you find yourself wanting to add a "convert reference to rate" method,
 * STOP and re-read docs/runbooks/material-pricing-classes.md. Class A and
 * Class B do not cross.
 */
class ReferencePriceService
{
    /**
     * Record a new reference price (memo). Append-only — never modifies an
     * existing row. Class B materials (platinum, copper) only.
     */
    public function recordReference(
        int $shopId,
        string $metalType,
        float $referencePrice,
        ?int $notedByUserId = null,
        ?string $note = null
    ): ShopMetalReferencePrice {
        if (! in_array($metalType, MetalRegistry::tier2Metals(), true)) {
            throw new \LogicException(
                "Reference prices apply only to Class B materials (Tier-2 metals). '{$metalType}' is not Class B."
            );
        }

        if ($referencePrice < 0) {
            throw new \LogicException('Reference price must be non-negative.');
        }

        return ShopMetalReferencePrice::create([
            'shop_id'           => $shopId,
            'metal_type'        => $metalType,
            'reference_price'   => round($referencePrice, 2),
            'noted_at'          => now(),
            'noted_by_user_id'  => $notedByUserId,
            'note'              => $note,
        ]);
    }

    /**
     * Most recently noted reference for a metal (display hint only — never
     * accounting). Returns null when no reference has ever been noted, which
     * is the normal state for most shops.
     */
    public function latestReference(int $shopId, string $metalType): ?ShopMetalReferencePrice
    {
        if (! in_array($metalType, MetalRegistry::tier2Metals(), true)) {
            return null;
        }

        // shop_id is passed explicitly, so bypass the tenant global scope.
        // This makes the service safe to call from CLI/scheduled contexts too.
        return ShopMetalReferencePrice::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('metal_type', $metalType)
            ->orderByDesc('noted_at')
            ->orderByDesc('id')
            ->first();
    }
}

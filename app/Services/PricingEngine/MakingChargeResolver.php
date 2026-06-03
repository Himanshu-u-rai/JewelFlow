<?php

namespace App\Services\PricingEngine;

/**
 * The ONE canonical making-charge resolution helper (MC-2).
 *
 * Every pricing surface (manufacturer PricingEngine, Quick Bill, retailer item
 * registration) resolves making through here — no duplicated rounding logic.
 * Pure function: same inputs → same ₹, to the paisa.
 *
 * Approved semantics (do not redesign here):
 *   - PERCENTAGE: of METAL VALUE only (not wastage/stone/GST/discount/total)
 *   - PER_GRAM:   of NET metal weight only (not gross)
 *   - FIXED:      the raw amount (current behaviour)
 *
 * The resolved ₹ is the only accounting truth; type/value are metadata.
 */
final class MakingChargeResolver
{
    /**
     * @param  string  $type       making_charge_type (NULL/unknown ⇒ fixed)
     * @param  float   $value       raw input: ₹ (fixed) | % (percentage) | ₹/g (per_gram)
     * @param  float   $metalValue  resolved metal value (fine weight × rate), for percentage
     * @param  float   $netWeight   net metal weight in grams, for per_gram
     * @return float   resolved making charge in ₹, rounded to 2 dp
     */
    public static function resolve(?string $type, float $value, float $metalValue, float $netWeight): float
    {
        return match (MakingChargeType::normalize($type)) {
            MakingChargeType::PERCENTAGE => round($metalValue * ($value / 100), 2),
            MakingChargeType::PER_GRAM   => round($netWeight * $value, 2),
            default                      => round($value, 2), // fixed
        };
    }
}

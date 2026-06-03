<?php

namespace App\Services\PricingEngine;

/**
 * Canonical making-charge mode vocabulary (MC-1 foundation).
 *
 * The three approved modes. All three RESOLVE to a final ₹ amount inside the
 * PricingEngine (MC-2); this class only names the modes and provides the
 * backward-compatible fallback used by serializers and read paths:
 *
 *   NULL / unknown  ⇒  FIXED   (legacy rows have no type; the stored
 *                                making_charges amount IS the value)
 *
 * Nothing here changes pricing behaviour — it is inert vocabulary + a
 * normaliser. The resolved making_charges ₹ amount remains the only
 * accounting truth; type/value are additive metadata.
 */
final class MakingChargeType
{
    /** Manual / fixed rupee amount — current behaviour, the safe default. */
    public const FIXED = 'fixed';

    /** Percentage of METAL VALUE only (not wastage/stone/GST/discount/total). */
    public const PERCENTAGE = 'percentage';

    /** Rupees per NET metal gram. */
    public const PER_GRAM = 'per_gram';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::FIXED, self::PERCENTAGE, self::PER_GRAM];
    }

    public static function isValid(?string $type): bool
    {
        return $type !== null && in_array($type, self::all(), true);
    }

    /**
     * Backward-compatible fallback: any NULL / unknown type reads as FIXED.
     * Legacy and pre-rollout rows therefore behave exactly as today.
     */
    public static function normalize(?string $type): string
    {
        return self::isValid($type) ? $type : self::FIXED;
    }
}

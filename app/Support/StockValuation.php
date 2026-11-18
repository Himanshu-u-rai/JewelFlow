<?php

namespace App\Support;

/** Indian AS-2 / ICDS-IV: closing stock at cost or net realisable value, whichever is lower. Unknowns stay NULL. */
final class StockValuation
{
    public static function marketValue(string|float|int|null $netMetalWeight, string|float|int|null $ratePerGram): ?float
    {
        if ($netMetalWeight === null || $ratePerGram === null) {
            return null;
        }

        return round((float) $netMetalWeight * (float) $ratePerGram, 2);
    }

    public static function lowerOfCostAndMarket(string|float|int|null $cost, string|float|int|null $market): ?float
    {
        if ($cost === null || $market === null) {
            return null;
        }

        return round(min((float) $cost, (float) $market), 2);
    }
}

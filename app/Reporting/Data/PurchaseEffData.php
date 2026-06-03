<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Purchase efficiency (Phase 2 M4, report #14).
 *
 * Compares the rate paid on stock purchases against the shop's own recorded
 * daily market reference rate (gold 24k / silver 999), normalized to each
 * line's purity, over a period. Premium = paid − market (positive = paid above
 * market). Lines on a date with no recorded market rate are excluded from the
 * premium and counted separately. Operational intelligence, no tax exposure.
 */
final class PurchaseEffData
{
    public function __construct(
        public readonly Collection $rows,   // per-metal: metal_type, line_count, lines_no_market, total_gross, purchase_cost, market_cost, premium, premium_pct
        public readonly float $totalGross,
        public readonly float $totalPurchaseCost,
        public readonly float $totalMarketCost,
        public readonly float $totalPremium,        // purchase_cost − market_cost (known-market lines)
        public readonly int $lineCount,
        public readonly int $linesNoMarket,
        public readonly string $periodLabel,
    ) {}
}

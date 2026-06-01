<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Inventory valuation snapshot at cost (Phase 2 M2).
 *
 * Valued from persisted Item.cost_price for on-hand (in_stock) items — no
 * market estimation, no dynamic heuristics (nxr5). Retail value is the
 * persisted tag price (selling_price), clearly labelled, not a market estimate.
 * Dead capital = value of items aged beyond the threshold.
 */
final class InventoryValuationData
{
    public function __construct(
        public readonly float $totalAtCost,
        public readonly float $totalAtRetail,   // persisted tag prices (selling_price)
        public readonly int $itemCount,
        public readonly int $costUnknownCount,   // in_stock items with no cost_price
        public readonly Collection $byCategory,  // category,count,cost_value,retail_value
        public readonly Collection $byMetal,     // metal_type,count,cost_value,fine_weight
        public readonly float $deadCapitalValue,
        public readonly int $deadCapitalCount,
        public readonly int $deadCapitalDays,    // aging threshold used
    ) {}
}

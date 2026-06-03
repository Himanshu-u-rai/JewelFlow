<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Dead stock / inventory aging at cost (Phase 2 M4, report #12).
 *
 * Buckets on-hand (in_stock) items by how long they have been in stock
 * (created_at proxy), valued at persisted Item.cost_price — capital that is not
 * turning over. Replaces the old heuristic "worst sellers". Snapshot; no market
 * estimation. Operational intelligence, no tax exposure.
 */
final class DeadStockData
{
    public function __construct(
        public readonly int $freshCount,    public readonly float $freshValue,    // 0–90 days
        public readonly int $agingCount,    public readonly float $agingValue,    // 91–180
        public readonly int $staleCount,    public readonly float $staleValue,    // 181–365
        public readonly int $deadCount,     public readonly float $deadValue,     // 365+
        public readonly float $totalValue,
        public readonly int $totalCount,
        public readonly int $costUnknownCount,
        public readonly Collection $oldest, // actionable: oldest items (barcode, design, category, metal_type, cost, age_days, created_at)
        public readonly string $asOf,
    ) {}
}

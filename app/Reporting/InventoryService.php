<?php

namespace App\Reporting;

use App\Models\Item;
use App\Reporting\Data\InventoryValuationData;
use Illuminate\Support\Facades\DB;

/**
 * Inventory reporting (Phase 2 M2): valuation at cost.
 *
 * Values on-hand (in_stock) inventory from persisted Item.cost_price — no
 * market estimation, no dynamic heuristics (nxr5). Retail value is the
 * persisted tag price (selling_price), labelled as such. Dead capital is the
 * value of items aged beyond the threshold (default 90 days). Snapshot of the
 * current authoritative inventory state.
 */
class InventoryService
{
    public function valuation(int $shopId, int $deadCapitalDays = 90): InventoryValuationData
    {
        $base = fn () => Item::withoutTenant()->where('shop_id', $shopId)->where('status', 'in_stock');

        $totals = $base()
            ->selectRaw('
                COALESCE(SUM(cost_price), 0)    as cost,
                COALESCE(SUM(selling_price), 0) as retail,
                COUNT(*)                         as cnt,
                COALESCE(SUM(CASE WHEN cost_price IS NULL THEN 1 ELSE 0 END), 0) as unknown
            ')
            ->first();

        $byCategory = $base()
            ->select(
                DB::raw("COALESCE(NULLIF(TRIM(category), ''), 'Uncategorised') as category"),
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(cost_price), 0) as cost_value'),
                DB::raw('COALESCE(SUM(selling_price), 0) as retail_value')
            )
            ->groupBy(DB::raw("COALESCE(NULLIF(TRIM(category), ''), 'Uncategorised')"))
            ->orderByDesc('cost_value')
            ->get();

        $byMetal = $base()
            ->select(
                DB::raw("COALESCE(NULLIF(TRIM(metal_type), ''), 'unknown') as metal_type"),
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(cost_price), 0) as cost_value'),
                DB::raw('COALESCE(SUM(net_metal_weight), 0) as fine_weight')
            )
            ->groupBy(DB::raw("COALESCE(NULLIF(TRIM(metal_type), ''), 'unknown')"))
            ->orderByDesc('cost_value')
            ->get();

        $cutoff = now()->subDays(max(1, $deadCapitalDays));
        $dead = $base()
            ->where('created_at', '<=', $cutoff)
            ->selectRaw('COALESCE(SUM(cost_price), 0) as value, COUNT(*) as cnt')
            ->first();

        return new InventoryValuationData(
            totalAtCost: round((float) $totals->cost, 2),
            totalAtRetail: round((float) $totals->retail, 2),
            itemCount: (int) $totals->cnt,
            costUnknownCount: (int) $totals->unknown,
            byCategory: $byCategory,
            byMetal: $byMetal,
            deadCapitalValue: round((float) $dead->value, 2),
            deadCapitalCount: (int) $dead->cnt,
            deadCapitalDays: $deadCapitalDays,
        );
    }
}

<?php

namespace App\Reporting;

use App\Models\Item;
use App\Reporting\Data\DeadStockData;
use App\Reporting\Data\InventoryValuationData;
use Carbon\Carbon;
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

    /**
     * #12 Dead stock / inventory aging at cost — on-hand items bucketed by age
     * (0–90 / 91–180 / 181–365 / 365+), valued at persisted cost_price, plus the
     * oldest non-moving items for action.
     */
    public function deadStock(int $shopId, int $oldestLimit = 100): DeadStockData
    {
        $asOf = Carbon::now();
        $base = fn () => Item::withoutTenant()->where('shop_id', $shopId)->where('status', 'in_stock');

        // One pass for bucketed counts + values at cost.
        $ageExpr = "EXTRACT(DAY FROM (now() - created_at))";
        $agg = $base()
            ->selectRaw("
                COUNT(*) as total_count,
                COALESCE(SUM(cost_price), 0) as total_value,
                COALESCE(SUM(CASE WHEN cost_price IS NULL THEN 1 ELSE 0 END), 0) as unknown,
                COALESCE(SUM(CASE WHEN {$ageExpr} <= 90 THEN 1 ELSE 0 END), 0) as fresh_c,
                COALESCE(SUM(CASE WHEN {$ageExpr} <= 90 THEN cost_price ELSE 0 END), 0) as fresh_v,
                COALESCE(SUM(CASE WHEN {$ageExpr} > 90 AND {$ageExpr} <= 180 THEN 1 ELSE 0 END), 0) as aging_c,
                COALESCE(SUM(CASE WHEN {$ageExpr} > 90 AND {$ageExpr} <= 180 THEN cost_price ELSE 0 END), 0) as aging_v,
                COALESCE(SUM(CASE WHEN {$ageExpr} > 180 AND {$ageExpr} <= 365 THEN 1 ELSE 0 END), 0) as stale_c,
                COALESCE(SUM(CASE WHEN {$ageExpr} > 180 AND {$ageExpr} <= 365 THEN cost_price ELSE 0 END), 0) as stale_v,
                COALESCE(SUM(CASE WHEN {$ageExpr} > 365 THEN 1 ELSE 0 END), 0) as dead_c,
                COALESCE(SUM(CASE WHEN {$ageExpr} > 365 THEN cost_price ELSE 0 END), 0) as dead_v
            ")
            ->first();

        // Actionable list: the oldest non-moving items (aged > 180 days), oldest first.
        $oldest = $base()
            ->whereRaw("{$ageExpr} > 180")
            ->select('barcode', 'design', 'category', 'metal_type', 'cost_price', 'created_at')
            ->orderBy('created_at')
            ->limit($oldestLimit)
            ->get()
            ->map(fn ($i) => (object) [
                'barcode'    => $i->barcode,
                'design'     => $i->design,
                'category'   => $i->category,
                'metal_type' => $i->metal_type,
                'cost_price' => round((float) $i->cost_price, 2),
                'age_days'   => (int) Carbon::parse($i->created_at)->diffInDays($asOf),
                'created_at' => $i->created_at,
            ]);

        return new DeadStockData(
            freshCount: (int) $agg->fresh_c, freshValue: round((float) $agg->fresh_v, 2),
            agingCount: (int) $agg->aging_c, agingValue: round((float) $agg->aging_v, 2),
            staleCount: (int) $agg->stale_c, staleValue: round((float) $agg->stale_v, 2),
            deadCount:  (int) $agg->dead_c,  deadValue:  round((float) $agg->dead_v, 2),
            totalValue: round((float) $agg->total_value, 2),
            totalCount: (int) $agg->total_count,
            costUnknownCount: (int) $agg->unknown,
            oldest: $oldest,
            asOf: $asOf->format('Y-m-d'),
        );
    }
}

<?php

namespace App\Reporting;

use App\Models\Item;
use App\Reporting\Data\DeadStockData;
use App\Reporting\Data\InventoryValuationData;
use App\Reporting\Data\PurchaseEffData;
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

    /**
     * #14 Purchase efficiency — purchase rate paid vs the shop's recorded daily
     * market rate (gold 24k / silver 999) normalized to each line's purity, over
     * a period. Premium = paid − market. Lines on a date with no recorded market
     * rate are excluded from the premium and counted separately.
     */
    public function purchaseEfficiency(int $shopId, ReportPeriod $period): PurchaseEffData
    {
        $lines = DB::table('stock_purchase_items as li')
            ->join('stock_purchases as p', 'p.id', '=', 'li.stock_purchase_id')
            ->where('li.shop_id', $shopId)
            ->whereIn('p.status', ['confirmed', 'stocked'])
            ->whereBetween('p.purchase_date', [$period->start()->toDateString(), $period->end()->toDateString()])
            ->whereNotNull('li.purchase_rate_per_gram')
            ->where('li.gross_weight', '>', 0)
            ->select('li.metal_type', 'li.purity', 'li.gross_weight', 'li.purchase_rate_per_gram', 'p.purchase_date')
            ->get();

        // Daily market rates for the shop, keyed by business_date.
        $rates = DB::table('shop_daily_metal_rates')
            ->where('shop_id', $shopId)
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->business_date)->toDateString());

        $byMetal = [];
        $lineCount = 0; $linesNoMarket = 0;
        $totalGross = 0.0; $totalPurchaseCost = 0.0;
        // Premium compares like-for-like — only lines that HAVE a market rate.
        $totalPurchaseCostKnown = 0.0; $totalMarketCost = 0.0;

        foreach ($lines as $li) {
            $metal = $li->metal_type ?: 'unknown';
            $gross = (float) $li->gross_weight;
            $purity = (float) $li->purity;
            $paidRate = (float) $li->purchase_rate_per_gram;
            $purchaseCost = $paidRate * $gross;

            $rate = $rates->get(Carbon::parse($li->purchase_date)->toDateString());
            $marketAtPurity = null;
            if ($rate) {
                if ($metal === 'gold' && $rate->gold_24k_rate_per_gram !== null && $purity > 0) {
                    $marketAtPurity = (float) $rate->gold_24k_rate_per_gram * ($purity / 24);
                } elseif ($metal === 'silver' && $rate->silver_999_rate_per_gram !== null && $purity > 0) {
                    $marketAtPurity = (float) $rate->silver_999_rate_per_gram * ($purity / 1000);
                }
            }

            $byMetal[$metal] ??= ['metal_type' => $metal, 'line_count' => 0, 'lines_no_market' => 0,
                'total_gross' => 0.0, 'purchase_cost' => 0.0, 'purchase_cost_known' => 0.0, 'market_cost' => 0.0];

            $byMetal[$metal]['line_count']++;
            $byMetal[$metal]['total_gross'] += $gross;
            $byMetal[$metal]['purchase_cost'] += $purchaseCost;
            $lineCount++;
            $totalGross += $gross;
            $totalPurchaseCost += $purchaseCost;

            if ($marketAtPurity !== null) {
                $marketCost = $marketAtPurity * $gross;
                $byMetal[$metal]['market_cost'] += $marketCost;
                $byMetal[$metal]['purchase_cost_known'] += $purchaseCost;
                $totalMarketCost += $marketCost;
                $totalPurchaseCostKnown += $purchaseCost;
            } else {
                $byMetal[$metal]['lines_no_market']++;
                $linesNoMarket++;
            }
        }

        $rows = collect($byMetal)->map(function ($m) {
            $premium = round($m['purchase_cost_known'] - $m['market_cost'], 2);
            return (object) [
                'metal_type'      => $m['metal_type'],
                'line_count'      => $m['line_count'],
                'lines_no_market' => $m['lines_no_market'],
                'total_gross'     => round($m['total_gross'], 3),
                'purchase_cost'   => round($m['purchase_cost'], 2),
                'market_cost'     => round($m['market_cost'], 2),
                'premium'         => $premium,
                'premium_pct'     => $m['market_cost'] > 0 ? round($premium / $m['market_cost'] * 100, 2) : 0.0,
            ];
        })->sortByDesc('total_gross')->values();

        return new PurchaseEffData(
            rows: $rows,
            totalGross: round($totalGross, 3),
            totalPurchaseCost: round($totalPurchaseCost, 2),
            totalMarketCost: round($totalMarketCost, 2),
            totalPremium: round($totalPurchaseCostKnown - $totalMarketCost, 2),
            lineCount: $lineCount,
            linesNoMarket: $linesNoMarket,
            periodLabel: $period->label(),
        );
    }
}

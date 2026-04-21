<?php

namespace App\Services;

use App\Models\Item;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RetailerReportService
{
    /**
     * Stock aging report — items grouped into aging buckets.
     * Cached for 10 minutes per shop.
     */
    public function stockAging(): array
    {
        $shopId = auth()->user()->shop_id;

        return Cache::remember("shop:{$shopId}:stock_aging", 600, function () use ($shopId) {
            return $this->computeStockAging($shopId);
        });
    }

    private function computeStockAging(int $shopId): array
    {
        $now = now();

        $items = Item::where('shop_id', $shopId)
            ->where('status', 'in_stock')
            ->select('id', 'barcode', 'category', 'sub_category', 'selling_price', 'created_at')
            ->get();

        $buckets = [
            '0-30 days' => ['count' => 0, 'value' => 0, 'items' => []],
            '31-60 days' => ['count' => 0, 'value' => 0, 'items' => []],
            '61-90 days' => ['count' => 0, 'value' => 0, 'items' => []],
            '91-180 days' => ['count' => 0, 'value' => 0, 'items' => []],
            '180+ days' => ['count' => 0, 'value' => 0, 'items' => []],
        ];

        $totalDays = 0;
        $agedCount = 0;

        foreach ($items as $item) {
            $days = (int) $item->created_at->diffInDays($now);
            $price = (float) $item->selling_price;

            if ($days <= 30) {
                $bucket = '0-30 days';
            } elseif ($days <= 60) {
                $bucket = '31-60 days';
            } elseif ($days <= 90) {
                $bucket = '61-90 days';
            } elseif ($days <= 180) {
                $bucket = '91-180 days';
            } else {
                $bucket = '180+ days';
            }

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['value'] += $price;
            $buckets[$bucket]['items'][] = $item;

            $totalDays += $days;
            if ($days > 90) {
                $agedCount++;
            }
        }

        $totalItems = $items->count();
        $buckets['__summary'] = [
            'avg_days'   => $totalItems > 0 ? (int) round($totalDays / $totalItems) : 0,
            'aged_pct'   => $totalItems > 0 ? round(($agedCount / $totalItems) * 100, 1) : 0.0,
            'aged_count' => $agedCount,
        ];

        return $buckets;
    }

    /**
     * Best sellers — top categories/items by sold count.
     * Cached for 10 minutes per shop+period.
     */
    public function bestSellers(int $limit = 10, string $period = '30'): array
    {
        $shopId = auth()->user()->shop_id;

        return Cache::remember("shop:{$shopId}:best_sellers:{$period}:{$limit}", 600, function () use ($shopId, $limit, $period) {
        $since = now()->subDays((int) $period);

        $byCategory = InvoiceItem::join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('items', 'items.id', '=', 'invoice_items.item_id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.created_at', '>=', $since)
            ->select('items.category', DB::raw('COUNT(*) as sold_count'), DB::raw('SUM(invoice_items.line_total) as total_revenue'))
            ->groupBy('items.category')
            ->orderByDesc('sold_count')
            ->limit($limit)
            ->get()
            ->toArray();

        $bySubCategory = InvoiceItem::join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('items', 'items.id', '=', 'invoice_items.item_id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.created_at', '>=', $since)
            ->select('items.category', 'items.sub_category', DB::raw('COUNT(*) as sold_count'), DB::raw('SUM(invoice_items.line_total) as total_revenue'))
            ->groupBy('items.category', 'items.sub_category')
            ->orderByDesc('sold_count')
            ->limit($limit)
            ->get()
            ->toArray();

        return [
            'by_category' => $byCategory,
            'by_sub_category' => $bySubCategory,
        ];
        });
    }

    /**
     * Worst sellers — categories with lowest movement.
     * Cached for 10 minutes per shop+period.
     */
    public function worstSellers(int $limit = 10, string $period = '30'): array
    {
        $shopId = auth()->user()->shop_id;

        return Cache::remember("shop:{$shopId}:worst_sellers:{$period}:{$limit}", 600, function () use ($shopId, $limit, $period) {
        $since = now()->subDays((int) $period);

        $byCategory = InvoiceItem::join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('items', 'items.id', '=', 'invoice_items.item_id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.created_at', '>=', $since)
            ->select('items.category', DB::raw('COUNT(*) as sold_count'), DB::raw('SUM(invoice_items.line_total) as total_revenue'))
            ->groupBy('items.category')
            ->orderBy('sold_count')
            ->limit($limit)
            ->get()
            ->toArray();

        return $byCategory;
        });
    }

    /**
     * Combined analytics dashboard data.
     */
    public function dashboardData(string $period = '30'): array
    {
        return [
            'stock_aging' => $this->stockAging(),
            'best_sellers' => $this->bestSellers(10, $period),
            'worst_sellers' => $this->worstSellers(10, $period),
        ];
    }
}

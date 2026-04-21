<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Item;
use App\Models\MetalLot;
use App\Models\Shop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TenantActivityMonitorService
{
    public function snapshot(?Carbon $date = null): array
    {
        $date = $date ? $date->copy() : now();
        $today = $date->toDateString();
        $windowStart = $date->copy()->subDays(6)->startOfDay();
        $windowEnd = $date->copy()->endOfDay();

        $invoicesToday = Invoice::withoutTenant()
            ->selectRaw('shop_id, count(*) as count')
            ->whereDate('created_at', $today)
            ->groupBy('shop_id')
            ->pluck('count', 'shop_id');

        $paymentsToday = InvoicePayment::withoutTenant()
            ->selectRaw('shop_id, count(*) as count')
            ->whereDate('created_at', $today)
            ->groupBy('shop_id')
            ->pluck('count', 'shop_id');

        $itemsToday = Item::withoutTenant()
            ->selectRaw('shop_id, count(*) as count')
            ->whereDate('created_at', $today)
            ->groupBy('shop_id')
            ->pluck('count', 'shop_id');

        $importsToday = Import::withoutTenant()
            ->selectRaw('shop_id, count(*) as count')
            ->where('status', Import::STATUS_COMPLETED)
            ->whereDate('finished_at', $today)
            ->groupBy('shop_id')
            ->pluck('count', 'shop_id');

        $metalLotsToday = MetalLot::withoutTenant()
            ->selectRaw('shop_id, count(*) as count')
            ->whereDate('created_at', $today)
            ->groupBy('shop_id')
            ->pluck('count', 'shop_id');

        $activeUsers = User::query()
            ->selectRaw('shop_id, count(*) as count')
            ->whereRaw('is_active IS TRUE')
            ->groupBy('shop_id')
            ->pluck('count', 'shop_id');

        $invoiceWindowCounts = Invoice::withoutTenant()
            ->selectRaw('shop_id, count(*) as count')
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->groupBy('shop_id')
            ->pluck('count', 'shop_id');

        $shops = Shop::query()
            ->select('id', 'name', 'shop_type', 'access_mode')
            ->orderBy('name')
            ->get();

        $rows = $shops->map(function (Shop $shop) use (
            $invoicesToday,
            $paymentsToday,
            $itemsToday,
            $importsToday,
            $metalLotsToday,
            $activeUsers,
            $invoiceWindowCounts
        ) {
            $shopId = $shop->id;
            $invoiceCount = (int) ($invoicesToday[$shopId] ?? 0);
            $paymentCount = (int) ($paymentsToday[$shopId] ?? 0);
            $itemCount = (int) ($itemsToday[$shopId] ?? 0);
            $importCount = (int) ($importsToday[$shopId] ?? 0);
            $metalLotCount = (int) ($metalLotsToday[$shopId] ?? 0);
            $activeUserCount = (int) ($activeUsers[$shopId] ?? 0);

            $windowCount = (int) ($invoiceWindowCounts[$shopId] ?? 0);
            $windowAvg = $windowCount / 7;
            $spike = $invoiceCount >= max(5, (int) ceil($windowAvg * 2));

            $activityScore = $invoiceCount + $paymentCount + $itemCount + ($importCount * 2) + $metalLotCount;

            return [
                'shop' => $shop,
                'invoices' => $invoiceCount,
                'pos_transactions' => $paymentCount,
                'items' => $itemCount,
                'imports' => $importCount,
                'metal_lots' => $metalLotCount,
                'active_users' => $activeUserCount,
                'activity_score' => $activityScore,
                'spike' => $spike,
                'window_avg' => round($windowAvg, 2),
            ];
        });

        $topActive = $rows->sortByDesc('activity_score')->take(10)->values();
        $spikes = $rows->filter(fn ($row) => $row['spike'])->values();
        $alerts = $rows->filter(fn ($row) => $row['activity_score'] >= 25)->values();

        return [
            'date' => $today,
            'rows' => $rows,
            'top_active' => $topActive,
            'spikes' => $spikes,
            'alerts' => $alerts,
        ];
    }
}

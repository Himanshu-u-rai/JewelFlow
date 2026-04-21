<?php

namespace App\Http\Controllers;

use App\Models\ShopPreferences;
use App\Services\DashboardMetricsService;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index()
    {
        $shopId = auth()->user()->shop_id;
        $today = now()->toDateString();
        $cacheKey = "dashboard:{$shopId}:{$today}";
        $cacheTtl = (int) config('performance.cache.dashboard_ttl_seconds', 300);

        $data = Cache::remember(
            $cacheKey,
            now()->addSeconds($cacheTtl),
            fn () => DashboardMetricsService::build((int) $shopId)
        );

        $preferences = ShopPreferences::where('shop_id', $shopId)->first();
        $data['lowStockThreshold'] = $preferences->low_stock_threshold ?? 20;
        $data['topCustomers'] = $data['topCustomers'] ?? collect();
        $data['monthlyRevenueTrend'] = $data['monthlyRevenueTrend'] ?? collect();
        $data['reorderAlerts'] = $data['reorderAlerts'] ?? collect();
        $data['reorderAlertCount'] = (int) ($data['reorderAlertCount'] ?? 0);

        return view('dashboard', $data);
    }
}

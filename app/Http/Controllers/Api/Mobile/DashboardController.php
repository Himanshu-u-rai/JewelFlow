<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\DashboardMetricsService;
use App\Services\ShopPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, ShopPricingService $pricing): JsonResponse
    {
        $shop = $request->user()->shop;
        $metrics = DashboardMetricsService::build($request->user()->shop_id);
        $dailyRate = $shop ? $pricing->currentDailyRate($shop) : null;

        return response()->json([
            'today' => [
                'revenue' => (float) ($metrics['todaysRevenue'] ?? 0),
                'profit' => (float) ($metrics['todaysProfit'] ?? 0),
                'invoices' => (int) ($metrics['invoicesToday'] ?? 0),
            ],
            'counts' => [
                'stock' => (int) ($metrics['stock'] ?? 0),
                'customers' => (int) ($metrics['customerCount'] ?? 0),
                'open_repairs' => (int) ($metrics['openRepairs'] ?? 0),
                'overdue_emis' => (int) ($metrics['overdueEmis'] ?? 0),
            ],
            'alerts' => [
                'reorder_count' => (int) ($metrics['reorderAlertCount'] ?? 0),
                'reorder_items' => $metrics['reorderAlerts'] ?? [],
            ],
            'trend' => $metrics['trendData'] ?? [],
            'is_retailer' => (bool) ($metrics['isRetailer'] ?? false),
            'metal_rates' => [
                'gold' => $dailyRate ? (float) $dailyRate->gold_24k_rate_per_gram : null,
                'silver' => $dailyRate ? (float) $dailyRate->silver_999_rate_per_gram : null,
                'gold_rate' => $dailyRate ? (float) $dailyRate->gold_24k_rate_per_gram : null,
                'silver_rate' => $dailyRate ? (float) $dailyRate->silver_999_rate_per_gram : null,
                'business_date' => $dailyRate?->business_date?->toDateString(),
                'source' => 'owner_daily_rates',
                'is_set' => (bool) $dailyRate,
            ],
        ]);
    }
}

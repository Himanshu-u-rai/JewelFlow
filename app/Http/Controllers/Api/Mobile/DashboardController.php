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
        // The web dashboard's rule: money figures need reports.view.
        $figures = $request->user()->can('reports.view');

        return response()->json([
            'figures_visible' => $figures,
            // The web shows today's invoice count to everyone; revenue and profit need reports.view.
            'today' => [
                'revenue' => $figures ? (float) ($metrics['todaysRevenue'] ?? 0) : null,
                'profit' => $figures ? (float) ($metrics['todaysProfit'] ?? 0) : null,
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
                // Every rule (the app's Shop notices page lists them all). A shop-wide
                // rule keeps a null category, so the app never filters stock by a label.
                'reorder_items' => collect($metrics['allReorderAlerts'] ?? [])
                    ->map(fn (array $alert): array => [
                        'category' => $alert['category'] ?: null,
                        'sub_category' => $alert['sub_category'] ?: null,
                        'current_stock' => (int) ($alert['current_stock'] ?? 0),
                        'threshold' => (int) ($alert['threshold'] ?? 0),
                        'vendor_name' => optional($alert['vendor'] ?? null)->name,
                    ])
                    ->values(),
                // The website dashboard's warning chips (repair backlog, low stock, overdue EMIs).
                'warnings' => DashboardMetricsService::warnings(
                    $metrics,
                    (int) ($shop?->preferences?->low_stock_threshold ?? 20),
                ),
            ],
            'trend' => $figures ? ($metrics['trendData'] ?? []) : [],
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

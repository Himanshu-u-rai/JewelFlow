<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $metrics = DashboardMetricsService::build($request->user()->shop_id);

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
        ]);
    }
}

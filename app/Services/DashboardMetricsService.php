<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Item;
use App\Models\Repair;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

class DashboardMetricsService
{
    public static function build(int $shopId): array
    {
        $today = now()->toDateString();
        $shop = Shop::query()->find($shopId);
        $isRetailer = $shop?->isRetailer() ?? false;

        $invoicesToday = Invoice::where('shop_id', $shopId)
            ->whereDate('created_at', $today)
            ->count();

        $openRepairs = Repair::where('shop_id', $shopId)
            ->where('status', 'pending')
            ->count();

        $stock = Item::where('shop_id', $shopId)
            ->where('status', 'in_stock')
            ->count();

        $customerCount = DB::table('customers')
            ->where('shop_id', $shopId)
            ->count();

        $todaysRevenue = 0;
        $todaysProfit = 0;
        $overdueEmis = 0;

        if ($isRetailer) {
            $todaysInvoices = Invoice::where('shop_id', $shopId)
                ->whereDate('created_at', $today)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->get();
            $todaysRevenue = $todaysInvoices->sum('total');

            $overdueEmis = InstallmentPlan::where('shop_id', $shopId)
                ->active()
                ->where('next_due_date', '<', now()->toDateString())
                ->count();

            $todaysItemIds = $todaysInvoices->pluck('id');
            if ($todaysItemIds->isNotEmpty()) {
                $todaysProfit = DB::table('invoice_items')
                    ->join('items', 'invoice_items.item_id', '=', 'items.id')
                    ->whereIn('invoice_items.invoice_id', $todaysItemIds)
                    ->selectRaw('SUM(invoice_items.line_total - items.cost_price) as profit')
                    ->value('profit') ?? 0;
            }
        }

        $invoiceTrend = Invoice::where('shop_id', $shopId)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw("DATE(created_at) as date, COUNT(*) as count, COALESCE(SUM(total), 0) as revenue")
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->keyBy('date');

        $profitTrend = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('items', 'items.id', '=', 'invoice_items.item_id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
            ->where('invoices.created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(invoices.created_at) as date, COALESCE(SUM(invoice_items.line_total - COALESCE(items.cost_price, 0)), 0) as profit')
            ->groupByRaw('DATE(invoices.created_at)')
            ->get()
            ->keyBy('date');

        $trendData = collect();
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $invoiceTrend->get($date);
            $profitRow = $profitTrend->get($date);
            $trendData->push([
                'date'  => $date,
                'label' => now()->subDays($i)->format('D'),
                'count' => $row ? (int) $row->count : 0,
                'revenue' => $row ? (float) $row->revenue : 0.0,
                'profit' => $profitRow ? (float) $profitRow->profit : 0.0,
            ]);
        }

        $monthlyRevenueRows = Invoice::where('shop_id', $shopId)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw("DATE(created_at) as date, COALESCE(SUM(total), 0) as revenue, COUNT(*) as invoice_count")
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->keyBy('date');

        $monthlyRevenueTrend = collect();
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $monthlyRevenueRows->get($date);
            $monthlyRevenueTrend->push([
                'date' => $date,
                'label' => now()->subDays($i)->format('M j'),
                'revenue' => $row ? (float) $row->revenue : 0.0,
                'invoice_count' => $row ? (int) $row->invoice_count : 0,
            ]);
        }

        $recentInvoices = Invoice::where('shop_id', $shopId)
            ->latest()
            ->take(5)
            ->get(['id', 'invoice_number', 'created_at']);

        $recentRepairs = Repair::where('shop_id', $shopId)
            ->latest()
            ->take(5)
            ->get(['id', 'item_description', 'status', 'created_at']);

        $topCustomers = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
            ->where('invoices.created_at', '>=', now()->subDays(30)->startOfDay())
            ->groupBy('customers.id', 'customers.first_name', 'customers.last_name', 'customers.mobile')
            ->selectRaw('
                customers.id,
                customers.first_name,
                customers.last_name,
                customers.mobile,
                COUNT(invoices.id) as invoice_count,
                COALESCE(SUM(invoices.total), 0) as total_spend
            ')
            ->orderByDesc('total_spend')
            ->orderByDesc('invoice_count')
            ->take(5)
            ->get();

        $allReorderAlerts = app(ReorderAlertService::class)->getAlerts($shopId);
        $reorderAlertCount = $allReorderAlerts->count();
        $reorderAlerts = $allReorderAlerts
            ->take(4)
            ->map(function (array $alert): array {
                return [
                    'category' => $alert['category'] ?: 'All Categories',
                    'sub_category' => $alert['sub_category'] ?: null,
                    'current_stock' => (int) ($alert['current_stock'] ?? 0),
                    'threshold' => (int) ($alert['threshold'] ?? 0),
                    'vendor_name' => optional($alert['vendor'] ?? null)->name,
                ];
            })
            ->values();

        return compact(
            'invoicesToday',
            'openRepairs',
            'stock',
            'customerCount',
            'trendData',
            'monthlyRevenueTrend',
            'recentInvoices',
            'recentRepairs',
            'topCustomers',
            'reorderAlertCount',
            'reorderAlerts',
            'isRetailer',
            'todaysRevenue',
            'todaysProfit',
            'overdueEmis'
        );
    }
}

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

        // Canonical sale definition: finalized only (drafts/cancelled excluded),
        // dated by accounting date — matches the GST, Closing and P&L reports so
        // "today's sales" is the same number everywhere (audit A4).
        $todayPeriod = \App\Reporting\ReportPeriod::day($today);

        // Bills raised today — a bill cancelled later still counts on the day it was raised; the reversal bill
        // that cancels it is not a bill raised to a customer, so it is not counted.
        $invoicesToday = Invoice::where('shop_id', $shopId)
            ->issuedSalesIn($todayPeriod)
            ->whereNull('reversal_of_invoice_id')
            ->count();

        ['openRepairs' => $openRepairs, 'stock' => $stock, 'overdueEmis' => $overdueEmis] = self::noticeCounts($shopId, $isRetailer);

        $customerCount = DB::table('customers')
            ->where('shop_id', $shopId)
            ->count();

        $todaysRevenue = 0;
        $todaysProfit = 0;

        if ($isRetailer) {
            [$dayStart, $dayEnd] = $todayPeriod->bounds();
            $todaysRevenue = round((float) self::salesByDay($shopId, $dayStart, $dayEnd)->sum('revenue'), 2);
            $todaysProfit = round((float) self::profitByDay($shopId, $dayStart, $dayEnd)->sum(), 2);
        }

        $invoiceTrend = self::salesByDay($shopId, now()->subDays(6)->startOfDay());

        $profitTrend = self::profitByDay($shopId, now()->subDays(6)->startOfDay())
            ->map(fn ($profit) => (object) ['profit' => $profit]);

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

        $monthlyRevenueRows = self::salesByDay($shopId, now()->subDays(29)->startOfDay());

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
            ->join('customers', fn ($j) => $j->on('customers.id', '=', 'invoices.customer_id')->on('customers.shop_id', '=', 'invoices.shop_id'))
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status', Invoice::STATUS_FINALIZED)
            // A customer metric (what they bought), not a period sales figure: a reversal bill is the shop
            // taking a sale back, and its original is already left out here as cancelled.
            ->whereNull('invoices.reversal_of_invoice_id')
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
            'allReorderAlerts',
            'isRetailer',
            'todaysRevenue',
            'todaysProfit',
            'overdueEmis'
        );
    }

    /**
     * Sales per day, the way the books see them: every bill ISSUED that day (also one cancelled later — it stays
     * on its own day) less the credit notes issued that day. A reversal bill is already negative, so it lands on
     * the day it was made and nets itself. Count = bills raised to customers (reversal bills are not counted).
     *
     * @return \Illuminate\Support\Collection<string, object{revenue: float, count: int, invoice_count: int}>
     */
    private static function salesByDay(int $shopId, $from, $to = null): \Illuminate\Support\Collection
    {
        $sold = Invoice::where('shop_id', $shopId)
            ->issuedSale()
            ->where('created_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw('DATE(created_at) as date, COALESCE(SUM(total), 0) as revenue, COUNT(*) FILTER (WHERE reversal_of_invoice_id IS NULL) as bills')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy('date');

        $returned = DB::table('credit_notes')
            ->where('shop_id', $shopId)
            ->where('issued_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('issued_at', '<=', $to))
            ->selectRaw('DATE(issued_at) as date, COALESCE(SUM(total), 0) as total')
            ->groupByRaw('DATE(issued_at)')
            ->pluck('total', 'date');

        $out = collect();
        foreach ($sold->keys()->merge($returned->keys())->unique()->sort() as $date) {
            $bills = (int) ($sold->get($date)->bills ?? 0);
            $out->put($date, (object) [
                'revenue' => round((float) ($sold->get($date)->revenue ?? 0) - (float) ($returned[$date] ?? 0), 2),
                'count' => $bills,
                'invoice_count' => $bills,
            ]);
        }

        return $out;
    }

    /**
     * Gross profit per day (line_total − cost) on the same rule as salesByDay(): bills issued that day, less the
     * lines of bills CANCELLED (reversed / fully returned) that day, less the value of credit notes issued that day
     * for bills that were not cancelled that same day (those lines are already taken off whole).
     *
     * @return \Illuminate\Support\Collection<string, float>
     */
    private static function profitByDay(int $shopId, $from, $to = null): \Illuminate\Support\Collection
    {
        $profit = 'COALESCE(SUM(invoice_items.line_total - COALESCE(items.cost_price, 0)), 0)';
        $lines = fn () => DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('items', fn ($j) => $j->on('items.id', '=', 'invoice_items.item_id')->on('items.shop_id', '=', 'invoices.shop_id'))
            ->where('invoices.shop_id', $shopId);

        $sold = $lines()
            ->whereRaw(Invoice::issuedSaleSql('invoices'))
            ->where('invoices.created_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('invoices.created_at', '<=', $to))
            ->selectRaw("DATE(invoices.created_at) as date, {$profit} as profit")
            ->groupByRaw('DATE(invoices.created_at)')
            ->pluck('profit', 'date');

        $cancelled = $lines()
            ->where('invoices.status', Invoice::STATUS_CANCELLED)
            ->where('invoices.cancelled_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('invoices.cancelled_at', '<=', $to))
            ->selectRaw("DATE(invoices.cancelled_at) as date, {$profit} as profit")
            ->groupByRaw('DATE(invoices.cancelled_at)')
            ->pluck('profit', 'date');

        $returned = DB::table('credit_notes')
            ->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            ->where('credit_notes.shop_id', $shopId)
            ->where('credit_notes.issued_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('credit_notes.issued_at', '<=', $to))
            ->whereRaw("NOT COALESCE(invoices.status = 'cancelled' AND DATE(invoices.cancelled_at) = DATE(credit_notes.issued_at), false)")
            ->selectRaw('DATE(credit_notes.issued_at) as date, COALESCE(SUM(credit_notes.subtotal), 0) as value')
            ->groupByRaw('DATE(credit_notes.issued_at)')
            ->pluck('value', 'date');

        $out = collect();
        foreach ($sold->keys()->merge($cancelled->keys())->merge($returned->keys())->unique()->sort() as $date) {
            $out->put($date, round((float) ($sold[$date] ?? 0) - (float) ($cancelled[$date] ?? 0) - (float) ($returned[$date] ?? 0), 2));
        }

        return $out;
    }

    /**
     * The counts the warnings read. "Open" = any repair not yet delivered — the
     * canonical definition used by RepairController::index(). The repair lifecycle
     * is received → in_repair → ready → delivered; there is no 'pending' status
     * (the old filter matched nothing, so the KPI always read 0).
     *
     * @return array{openRepairs: int, stock: int, overdueEmis: int, isRetailer: bool}
     */
    public static function noticeCounts(int $shopId, bool $isRetailer): array
    {
        return [
            'openRepairs' => Repair::where('shop_id', $shopId)->whereNotIn('status', ['delivered'])->count(),
            'stock' => Item::where('shop_id', $shopId)->where('status', 'in_stock')->count(),
            'overdueEmis' => $isRetailer
                ? InstallmentPlan::where('shop_id', $shopId)->active()->where('next_due_date', '<', now()->toDateString())->count()
                : 0,
            'isRetailer' => $isRetailer,
        ];
    }

    /**
     * The dashboard's warnings: the website shows the messages, the app's
     * Shop notices page lists them by key. One rule for both.
     *
     * @return list<array{key: string, count: int, message: string}>
     */
    public static function warnings(array $metrics, int $lowStockThreshold): array
    {
        $openRepairs = (int) ($metrics['openRepairs'] ?? 0);
        $stock = (int) ($metrics['stock'] ?? 0);
        $overdueEmis = (int) ($metrics['overdueEmis'] ?? 0);

        $warnings = [];
        if ($openRepairs > 8) {
            $warnings[] = ['key' => 'repair_backlog', 'count' => $openRepairs, 'message' => "Repair backlog is high ({$openRepairs})"];
        }
        if ($stock < $lowStockThreshold) {
            $warnings[] = ['key' => 'low_stock', 'count' => $stock, 'message' => "Low stock: only {$stock} item(s) in stock"];
        }
        if (($metrics['isRetailer'] ?? false) && $overdueEmis > 0) {
            $warnings[] = ['key' => 'overdue_emis', 'count' => $overdueEmis, 'message' => "{$overdueEmis} EMI plan(s) are overdue"];
        }

        return $warnings;
    }
}

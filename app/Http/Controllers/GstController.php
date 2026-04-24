<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GstController extends Controller
{
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        // Normalize and clamp month/year to avoid Carbon errors from arbitrary string input.
        $monthInput = $request->input('month', now()->month);
        $yearInput  = $request->input('year', now()->year);
        $month = max(1, min(12, is_numeric($monthInput) ? (int) $monthInput : (int) now()->month));
        $year  = max(2000, min(2100, is_numeric($yearInput)  ? (int) $yearInput  : (int) now()->year));

        $startDate = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
        $endDate   = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();

        // Single aggregated query grouped by GST rate.
        // Totals are derived by summing the breakdown rows — no second query needed.
        $gstBreakdown = Invoice::where('shop_id', $shopId)
            ->where('status', Invoice::STATUS_FINALIZED)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                'gst_rate',
                DB::raw('SUM(subtotal) as taxable'),
                DB::raw('SUM(discount) as discount'),
                DB::raw('SUM(gst) as gst'),
                DB::raw('SUM(total) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('gst_rate')
            ->orderBy('gst_rate')
            ->get();

        // Derive totals from the breakdown — avoids a second full-table scan.
        $taxableAmount = round((float) $gstBreakdown->sum('taxable'), 2);
        $totalDiscount = round((float) $gstBreakdown->sum('discount'), 2);
        $gstCollected  = round((float) $gstBreakdown->sum('gst'), 2);
        $totalSales    = round((float) $gstBreakdown->sum('total'), 2);
        $invoiceCount  = (int) $gstBreakdown->sum('count');

        return view('reports.gst', compact(
            'month',
            'year',
            'totalSales',
            'taxableAmount',
            'totalDiscount',
            'gstCollected',
            'invoiceCount',
            'gstBreakdown'
        ));
    }
}

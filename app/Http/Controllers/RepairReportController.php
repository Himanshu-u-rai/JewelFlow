<?php

namespace App\Http\Controllers;

use App\Models\Repair;
use Illuminate\Http\Request;

class RepairReportController extends Controller
{
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        // Validate date inputs.
        $fromDate = $request->filled('from_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->from_date)
            ? $request->from_date : null;
        $toDate = $request->filled('to_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->to_date)
            ? $request->to_date : null;
        $status = $request->filled('status') ? $request->status : null;

        $query = Repair::where('shop_id', $shopId)->with(['customer', 'invoice']);

        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }
        if ($status) {
            $query->where('status', $status);
        }

        $repairs = $query->orderBy('created_at', 'desc')->paginate(25)->withQueryString();

        // Aggregate totals for the filtered set — separate query avoids in-PHP collection math.
        $totalsQuery = Repair::where('shop_id', $shopId);
        if ($fromDate) {
            $totalsQuery->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $totalsQuery->whereDate('created_at', '<=', $toDate);
        }
        if ($status) {
            $totalsQuery->where('status', $status);
        }

        $totals = $totalsQuery->selectRaw("
            COALESCE(SUM(gold_issued_fine), 0) as total_issued,
            COALESCE(SUM(gold_returned_fine), 0) as total_returned,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN final_cost ELSE 0 END), 0) as total_cash
        ")->first();

        return view('report_repairs', compact('repairs', 'totals', 'fromDate', 'toDate', 'status'));
    }
}

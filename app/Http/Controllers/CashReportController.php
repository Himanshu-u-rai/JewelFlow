<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashReportController extends Controller
{
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        
        // Validate date — reject anything that isn't YYYY-MM-DD.
        $dateInput = $request->input('date', now()->toDateString());
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput) ? $dateInput : now()->toDateString();

        $rows = CashTransaction::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->select(
                'type',
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('type')
            ->get();

        // Payment mode breakdown for the day
        $modeBreakdown = CashTransaction::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->whereNotNull('payment_mode')
            ->select(
                'payment_mode',
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('payment_mode')
            ->pluck('total', 'payment_mode');

        return view('report_cash', compact('rows', 'date', 'modeBreakdown'));
    }
}
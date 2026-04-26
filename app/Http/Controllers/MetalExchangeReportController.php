<?php

namespace App\Http\Controllers;

use App\Models\InvoicePayment;
use Illuminate\Http\Request;

class MetalExchangeReportController extends Controller
{
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to',   now()->toDateString());

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = now()->startOfMonth()->toDateString();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = now()->toDateString();

        $rows = InvoicePayment::with(['invoice.customer'])
            ->whereHas('invoice', fn($q) => $q->where('shop_id', $shopId))
            ->whereIn('mode', ['old_gold', 'old_silver'])
            ->whereHas('invoice', fn($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->get();

        $goldRows   = $rows->where('mode', 'old_gold');
        $silverRows = $rows->where('mode', 'old_silver');

        $goldSummary = [
            'gross'  => $goldRows->sum('metal_gross_weight'),
            'fine'   => $goldRows->sum('metal_fine_weight'),
            'value'  => $goldRows->sum('amount'),
            'count'  => $goldRows->count(),
        ];
        $silverSummary = [
            'gross'  => $silverRows->sum('metal_gross_weight'),
            'fine'   => $silverRows->sum('metal_fine_weight'),
            'value'  => $silverRows->sum('amount'),
            'count'  => $silverRows->count(),
        ];

        return view('report_metal_exchange', compact('rows', 'from', 'to', 'goldSummary', 'silverSummary'));
    }
}

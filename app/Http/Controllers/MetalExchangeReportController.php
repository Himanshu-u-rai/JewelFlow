<?php

namespace App\Http\Controllers;

use App\Reporting\SalesService;
use Illuminate\Http\Request;

class MetalExchangeReportController extends Controller
{
    public function __construct(private SalesService $sales) {}

    public function index(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;
        $view   = $request->input('view', 'transactions');

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to',   now()->toDateString());

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = now()->startOfMonth()->toDateString();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = now()->toDateString();

        if ($view === 'lots') {
            $weeklyLots = $this->sales->metalExchangeLots($shopId, $from, $to);

            return view('report_metal_exchange', compact('weeklyLots', 'view', 'from', 'to'));
        }

        // Default: transaction-level view.
        $data = $this->sales->metalExchange($shopId, $from, $to);

        return view('report_metal_exchange', [
            'rows'          => $data->rows,
            'from'          => $from,
            'to'            => $to,
            'goldSummary'   => $data->goldSummary,
            'silverSummary' => $data->silverSummary,
            'view'          => $view,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Reporting\ProfitReportingService;
use App\Reporting\ReportPeriod;
use Illuminate\Http\Request;

class PnlController extends Controller
{
    public function __construct(private ProfitReportingService $profitReporting) {}

    public function index(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;

        // A specific date → that day; otherwise the current month (a range, not
        // just "today"). Parsing/validation lives in ReportPeriod.
        $period = $request->filled('date')
            ? ReportPeriod::day($request->input('date'))
            : ReportPeriod::month();

        $data = $this->profitReporting->summary($shopId, $period);

        return view('report_pnl', [
            // Filter/header state.
            'date'        => $request->input('date', ''),
            'periodLabel' => $period->label(),

            // Honest gross-margin figures (mapped to the view's existing names).
            'sales'            => $data->revenue,       // ex-GST net sales (revenue base)
            'goldValue'        => $data->cogs,          // now true COGS, not an avg-rate estimate
            'profit'           => $data->grossProfit,   // revenue − COGS (can be negative)
            'making'           => $data->makingCharges,
            'stones'           => $data->stoneCharges,
            'wastageRecovered' => $data->wastageRecovered,

            // New transparency fields.
            'marginPct'        => $data->marginPct,
            'grossSales'       => $data->grossSales,
            'returns'          => $data->returns,
            'discount'         => $data->discount,
            'costUnknownLines' => $data->costUnknownLines,
            'soldLineCount'    => $data->soldLineCount,
        ]);
    }
}

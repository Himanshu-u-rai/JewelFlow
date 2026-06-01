<?php

namespace App\Http\Controllers;

use App\Reporting\GstReportingService;
use App\Reporting\ReportPeriod;
use Illuminate\Http\Request;

class GstController extends Controller
{
    public function __construct(private GstReportingService $gstReporting) {}

    public function index(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;

        // Canonical period (month) — parsing/clamping lives in ReportPeriod.
        $period = ReportPeriod::month($request->input('year'), $request->input('month'));
        $data   = $this->gstReporting->summary($shopId, $period);

        // Keep month/year for the view's existing filter controls.
        $month = (int) $period->start()->month;
        $year  = (int) $period->start()->year;

        return view('reports.gst', [
            // Existing variables (back-compat).
            'month'         => $month,
            'year'          => $year,
            'gstBreakdown'  => $data->breakdown,
            'totalSales'    => $data->totalSales,
            'taxableAmount' => $data->taxableAmount,
            'totalDiscount' => $data->totalDiscount,
            'gstCollected'  => $data->gstCollected,
            'invoiceCount'  => $data->invoiceCount,
            // New: CGST/SGST/IGST split (A7).
            'cgstCollected' => $data->cgstCollected,
            'sgstCollected' => $data->sgstCollected,
            'igstCollected' => $data->igstCollected,
            // New: credit-note reversals + net liability (A1).
            'cnData'             => $data->creditNotes,
            'cnSubtotalReversed' => $data->cnTaxableReversed,
            'cnGstReversed'      => $data->cnGstReversed,
            'cnTotalReversed'    => $data->cnTotalReversed,
            'cnCount'            => $data->cnCount,
            'netGstLiability'    => $data->netGstLiability,
        ]);
    }
}

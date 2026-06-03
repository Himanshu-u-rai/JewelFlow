<?php

namespace App\Http\Controllers;

use App\Reporting\RepairService;
use Illuminate\Http\Request;

class RepairReportController extends Controller
{
    public function __construct(private RepairService $repairs) {}

    public function index(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;

        // Validate date inputs.
        $fromDate = $request->filled('from_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->from_date)
            ? $request->from_date : null;
        $toDate = $request->filled('to_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->to_date)
            ? $request->to_date : null;
        $status = $request->filled('status') ? $request->status : null;

        $data = $this->repairs->summary($shopId, $fromDate, $toDate, $status);

        return view('report_repairs', [
            'repairs'  => $data->repairs,
            'totals'   => $data->totals,
            'fromDate' => $fromDate,
            'toDate'   => $toDate,
            'status'   => $status,
        ]);
    }
}

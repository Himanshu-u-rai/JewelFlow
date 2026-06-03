<?php

namespace App\Http\Controllers;

use App\Reporting\LedgerService;
use Illuminate\Http\Request;

class CashReportController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    public function index(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;

        // Validate date — reject anything that isn't YYYY-MM-DD.
        $dateInput = $request->input('date', now()->toDateString());
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput) ? $dateInput : now()->toDateString();

        $data = $this->ledger->cashDay($shopId, $date);

        return view('report_cash', [
            'rows'          => $data->rows,
            'date'          => $date,
            'modeBreakdown' => $data->modeBreakdown,
        ]);
    }
}

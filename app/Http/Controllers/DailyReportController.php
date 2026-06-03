<?php

namespace App\Http\Controllers;

use App\Reporting\LedgerService;

class DailyReportController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    public function index()
    {
        $shopId = (int) auth()->user()->shop_id;
        $date = request('date', now()->toDateString());

        $rows = $this->ledger->metalMovementDay($shopId, $date);

        return view('report_daily', compact('rows', 'date'));
    }
}

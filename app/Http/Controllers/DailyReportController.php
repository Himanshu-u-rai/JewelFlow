<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Models\MetalMovement;

class DailyReportController extends Controller
{
    public function index()
    {
        $shopId = auth()->user()->shop_id;
        $date = request('date', now()->toDateString());

        $rows = MetalMovement::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->select(
                'type',
                DB::raw('SUM(fine_weight) as total')
            )
            ->groupBy('type')
            ->get();

        return view('report_daily', compact('rows', 'date'));
    }
}

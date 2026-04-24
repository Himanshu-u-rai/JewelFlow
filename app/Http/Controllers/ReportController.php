<?php

namespace App\Http\Controllers;

use App\Models\MetalLot;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function gold()
    {
        $balances = MetalLot::where('shop_id', auth()->user()->shop_id)
            ->select(
                'purity',
                DB::raw('SUM(fine_weight_remaining) as total_fine')
            )
            ->groupBy('purity')
            ->orderBy('purity', 'desc')
            ->get();

        return view('reports.gold', compact('balances'));
    }
}

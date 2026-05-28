<?php

namespace App\Http\Controllers;

use App\Models\MetalLot;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function gold()
    {
        // Group by (metal_type, purity) so different metals at the same purity
        // are never merged into one balance line — same invariant the vault
        // balances enforce (CONSTITUTION.md Article XIII/XIV).
        $balances = MetalLot::where('shop_id', auth()->user()->shop_id)
            ->select(
                'metal_type',
                'purity',
                DB::raw('SUM(fine_weight_remaining) as total_fine')
            )
            ->groupBy('metal_type', 'purity')
            ->orderBy('metal_type')
            ->orderBy('purity', 'desc')
            ->get();

        return view('reports.gold', compact('balances'));
    }
}

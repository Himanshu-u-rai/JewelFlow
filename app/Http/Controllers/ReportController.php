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

    /**
     * Reports hub — a grouped landing page so a shop owner can find the right
     * report without scanning a long flat nav list. Edition-specific reports are
     * shown only for the relevant edition.
     */
    public function hub()
    {
        $shop = auth()->user()->shop;

        return view('reports.hub', [
            'isRetailer'     => (bool) $shop?->isRetailer(),
            'isManufacturer' => (bool) $shop?->isManufacturer(),
        ]);
    }
}

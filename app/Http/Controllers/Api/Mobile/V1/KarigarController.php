<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\Karigar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile v1 — Karigar list + show (M9).
 *
 * Read-only. Karigars are created and managed on the web.
 */
class KarigarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $karigars = Karigar::where('shop_id', $shopId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'mobile', 'notes', 'default_wastage_percent']);

        return response()->json([
            'data' => $karigars->map(fn ($k) => [
                'id'                      => $k->id,
                'name'                    => $k->name,
                'mobile'                  => $k->mobile,
                'notes'                   => $k->notes,
                'default_wastage_percent' => (float) $k->default_wastage_percent,
            ])->values()->all(),
        ]);
    }

    public function show(Request $request, Karigar $karigar): JsonResponse
    {
        abort_if($karigar->shop_id !== (int) $request->user()->shop_id, 404);

        $karigar->loadMissing([]);

        // Outstanding balance: sum of fine weight across open job orders.
        $outstandingFine = \App\Models\JobOrder::where('shop_id', $karigar->shop_id)
            ->where('karigar_id', $karigar->id)
            ->whereIn('status', [\App\Models\JobOrder::STATUS_ISSUED, \App\Models\JobOrder::STATUS_PARTIAL_RETURN])
            ->sum(\Illuminate\Support\Facades\DB::raw('issued_fine_weight - COALESCE(returned_fine_weight, 0) - COALESCE(leftover_returned_fine_weight, 0) - COALESCE(actual_wastage_fine, 0)'));

        return response()->json([
            'id'                      => $karigar->id,
            'name'                    => $karigar->name,
            'mobile'                  => $karigar->mobile,
            'notes'                   => $karigar->notes,
            'default_wastage_percent' => (float) $karigar->default_wastage_percent,
            'outstanding_fine_weight' => round((float) $outstandingFine, 4),
        ]);
    }
}

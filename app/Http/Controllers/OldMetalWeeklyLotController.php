<?php

namespace App\Http\Controllers;

use App\Models\MetalLot;
use App\Services\OldMetalWeeklyLotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OldMetalWeeklyLotController extends Controller
{
    public function dispatch(Request $request, MetalLot $metalLot): RedirectResponse
    {
        abort_unless(
            in_array($metalLot->source, [MetalLot::SOURCE_OLD_GOLD_WEEKLY, MetalLot::SOURCE_OLD_SILVER_WEEKLY])
            && (int) $metalLot->shop_id === (int) auth()->user()->shop_id,
            404
        );

        $validated = $request->validate([
            'dispatch_notes' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        app(OldMetalWeeklyLotService::class)->dispatch($metalLot, $validated['dispatch_notes']);

        return back()->with('success', 'Lot marked as dispatched.');
    }
}

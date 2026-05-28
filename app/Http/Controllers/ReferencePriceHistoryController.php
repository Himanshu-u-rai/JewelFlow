<?php

namespace App\Http\Controllers;

use App\Models\ShopMetalReferencePrice;
use App\Services\MetalRegistry;
use Illuminate\Http\Request;

/**
 * Reference Prices history report (Class B only — platinum, copper).
 *
 * A standalone, append-only timeline of operator-noted reference prices.
 * This screen is a MEMO surface, NOT an accounting report. It must never:
 *   - join to `shop_daily_metal_rates` or any class-A storage
 *   - import `ShopPricingService` / call `resolvedRateForToday` / etc.
 *   - feed PnL, Closing, vault, GST, or reconciliation
 *
 * Gold/silver shops see "no Tier-2 metal enabled — nothing to show." That is
 * the correct empty state, not an error.
 */
class ReferencePriceHistoryController extends Controller
{
    public function index(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;

        $tier2 = MetalRegistry::tier2Metals();

        // Per-metal timeline. Each row is one operator-noted reference event.
        // Ordered newest-first because operators want to see the latest first.
        $timelines = [];
        foreach ($tier2 as $metal) {
            $timelines[$metal] = ShopMetalReferencePrice::withoutTenant()
                ->where('shop_id', $shopId)
                ->where('metal_type', $metal)
                ->with(['notedBy:id,name'])
                ->orderByDesc('noted_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get();
        }

        return view('reports.reference-prices', compact('timelines', 'tier2'));
    }
}

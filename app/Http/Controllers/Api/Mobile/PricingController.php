<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\ShopPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

class PricingController extends Controller
{
    public function __construct(private ShopPricingService $pricing) {}

    public function today(Request $request): JsonResponse
    {
        $shop = $request->user()->shop;

        if (! $shop || ! $shop->isRetailer()) {
            return response()->json(['message' => 'Daily pricing is only available for retailer shops.'], 404);
        }

        $dailyRate = $this->pricing->currentDailyRate($shop);

        return response()->json([
            'business_date'           => $this->pricing->businessDateString($shop),
            'gold_24k_rate_per_gram'  => $dailyRate ? (float) $dailyRate->gold_24k_rate_per_gram : null,
            'silver_999_rate_per_gram' => $dailyRate ? (float) $dailyRate->silver_999_rate_per_gram : null,
            'silver_999_rate_per_kg'  => $dailyRate ? round((float) $dailyRate->silver_999_rate_per_gram * 1000, 4) : null,
            'rates_set_today'         => $dailyRate !== null,
            'last_updated_at'         => $dailyRate?->updated_at?->toIso8601String(),
        ]);
    }

    public function saveToday(Request $request): JsonResponse
    {
        $shop = $request->user()->shop;

        if (! $shop || ! $shop->isRetailer()) {
            return response()->json(['message' => 'Daily pricing is only available for retailer shops.'], 404);
        }

        $validated = $request->validate([
            'gold_24k_rate_per_gram' => 'required|numeric|min:0.0001|max:999999.9999',
            'silver_999_rate_per_kg' => 'required|numeric|min:0.0001|max:999999999.9999',
        ]);

        try {
            $dailyRate = $this->pricing->saveTodayBaseRates($shop, (int) $request->user()->id, [
                'gold_24k_rate_per_gram'   => (float) $validated['gold_24k_rate_per_gram'],
                'silver_999_rate_per_gram' => round(((float) $validated['silver_999_rate_per_kg']) / 1000, 4),
            ]);
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'                 => "Today's pricing rates saved and stock repricing has been queued.",
            'business_date'           => $dailyRate->business_date->toDateString(),
            'gold_24k_rate_per_gram'  => (float) $dailyRate->gold_24k_rate_per_gram,
            'silver_999_rate_per_gram' => (float) $dailyRate->silver_999_rate_per_gram,
            'silver_999_rate_per_kg'  => round((float) $dailyRate->silver_999_rate_per_gram * 1000, 4),
            'last_updated_at'         => $dailyRate->updated_at?->toIso8601String(),
        ]);
    }
}

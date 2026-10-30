<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopMetalPurityProfile;
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

    /**
     * Rates by purity: the web's "Today's Resolved Rates" table — every purity,
     * today's rate per gram, and whether it is the owner's own (override) or
     * worked out from the base rates. Owner only (owner's decision 2026-10-25).
     */
    public function purityRates(Request $request): JsonResponse
    {
        $shop = $request->user()->shop;
        if (! $shop || ! $shop->isRetailer()) {
            return response()->json(['message' => 'Daily pricing is only available for retailer shops.'], 404);
        }
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => \App\Support\NotAvailable::MESSAGE], 403); // owner only
        }

        return response()->json($this->purityRatesPayload($shop));
    }

    /** The web's same-day purity override (PricingSettingsController::storeOverride), owner only. */
    public function overridePurityRate(Request $request, int $profile): JsonResponse
    {
        $shop = $request->user()->shop;
        if (! $shop || ! $shop->isRetailer()) {
            return response()->json(['message' => 'Daily pricing is only available for retailer shops.'], 404);
        }
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => \App\Support\NotAvailable::MESSAGE], 403); // owner only
        }

        $purity = ShopMetalPurityProfile::withoutTenant()->where('shop_id', $shop->id)->find($profile);
        if (! $purity) {
            return response()->json(['message' => 'Purity not found.'], 404);
        }

        $validated = $request->validate([
            'rate_per_gram' => 'required|numeric|min:0.0001|max:999999.9999',
        ]);

        try {
            $this->pricing->saveSameDayOverride($shop, $purity, (float) $validated['rate_per_gram']);
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge(
            ['message' => 'Your rate is saved for today and stock repricing has been queued.'],
            $this->purityRatesPayload($shop),
        ));
    }

    private function purityRatesPayload(Shop $shop): array
    {
        return [
            'business_date'   => $this->pricing->businessDateString($shop),
            'rates_set_today' => $this->pricing->currentDailyRate($shop) !== null,
            'purity_rates'    => $this->pricing->resolvedRateGrid($shop)->map(fn (array $row) => [
                'profile_id'    => (int) $row['profile']->id,
                'metal_type'    => (string) $row['profile']->metal_type,
                'label'         => (string) $row['profile']->label,
                'purity_value'  => (float) $row['profile']->purity_value,
                'is_active'     => (bool) $row['profile']->is_active,
                'rate_per_gram' => $row['rate_per_gram'] !== null ? round((float) $row['rate_per_gram'], 4) : null,
                'is_override'   => (bool) $row['is_override'],
            ])->values()->all(),
        ];
    }
}

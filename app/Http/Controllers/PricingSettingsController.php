<?php

namespace App\Http\Controllers;

use App\Jobs\RepriceRetailerInventoryJob;
use App\Models\Item;
use App\Models\ShopMetalPurityProfile;
use App\Models\ShopPreferences;
use App\Services\ShopPricingService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PricingSettingsController extends Controller
{
    public function saveTodayRates(Request $request, ShopPricingService $pricing): RedirectResponse
    {
        $bag = $request->input('context') === 'modal' ? 'pricingModal' : 'pricing';

        $validated = Validator::make($request->all(), [
            'gold_24k_rate_per_gram' => 'required|numeric|min:0.0001|max:999999.9999',
            'silver_999_rate_per_kg' => 'required|numeric|min:0.0001|max:999999999.9999',
            'redirect_to' => 'nullable|string|max:2000',
        ])->validateWithBag($bag);

        $shop = $request->user()->shop;
        abort_unless($shop && $shop->isRetailer(), 404);

        $pricing->saveTodayBaseRates($shop, (int) $request->user()->id, [
            'gold_24k_rate_per_gram' => (float) $validated['gold_24k_rate_per_gram'],
            'silver_999_rate_per_gram' => round(((float) $validated['silver_999_rate_per_kg']) / 1000, 4),
        ]);

        $redirectTo = $validated['redirect_to'] ?? route('settings.edit', ['tab' => 'pricing']);

        return redirect()->to($redirectTo)
            ->with('success', 'Today\'s pricing rates were saved and stock repricing has been queued.');
    }

    public function updateTimezone(Request $request): RedirectResponse
    {
        $shop = $request->user()->shop;
        abort_unless($shop && $shop->isRetailer(), 404);

        $validated = $request->validate([
            'pricing_timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $preferences = $shop->preferences ?? new ShopPreferences(['shop_id' => $shop->id]);
        $preferences->pricing_timezone = $validated['pricing_timezone'];
        $preferences->save();

        return redirect()->route('settings.edit', ['tab' => 'pricing'])
            ->with('success', 'Pricing timezone updated successfully.');
    }

    public function storeProfile(Request $request, ShopPricingService $pricing): RedirectResponse
    {
        $shop = $request->user()->shop;
        abort_unless($shop && $shop->isRetailer(), 404);

        $validated = $request->validate([
            'metal_type' => ['required', Rule::in(['gold', 'silver'])],
            'code' => 'nullable|string|max:30',
            'label' => 'nullable|string|max:60',
            'purity_value' => 'required|numeric|min:0.001|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        $pricing->upsertPurityProfile($shop, array_merge($validated, [
            'is_active' => $request->boolean('is_active', true),
        ]));

        if ($dailyRate = $pricing->currentDailyRate($shop)) {
            $pricing->resolveAndRecordCurrentDayRates($dailyRate, true);
            RepriceRetailerInventoryJob::dispatch((int) $shop->id);
        }

        return redirect()->route('settings.edit', ['tab' => 'pricing'])
            ->with('success', 'Purity profile created successfully.');
    }

    public function updateProfile(
        Request $request,
        ShopMetalPurityProfile $profile,
        ShopPricingService $pricing
    ): RedirectResponse {
        $shop = $request->user()->shop;
        abort_unless($shop && $shop->isRetailer(), 404);
        abort_if((int) $profile->shop_id !== (int) $shop->id, 404);

        $validated = $request->validate([
            'metal_type' => ['required', Rule::in(['gold', 'silver'])],
            'code' => 'nullable|string|max:30',
            'label' => 'nullable|string|max:60',
            'purity_value' => 'required|numeric|min:0.001|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        $pricing->upsertPurityProfile($shop, array_merge($validated, [
            'is_active' => $request->boolean('is_active'),
        ]), $profile);

        if ($dailyRate = $pricing->currentDailyRate($shop)) {
            $pricing->resolveAndRecordCurrentDayRates($dailyRate, true);
            RepriceRetailerInventoryJob::dispatch((int) $shop->id);
        }

        return redirect()->route('settings.edit', ['tab' => 'pricing'])
            ->with('success', 'Purity profile updated successfully.');
    }

    public function storeOverride(
        Request $request,
        ShopMetalPurityProfile $profile,
        ShopPricingService $pricing
    ): RedirectResponse {
        $shop = $request->user()->shop;
        abort_unless($shop && $shop->isRetailer(), 404);
        abort_if((int) $profile->shop_id !== (int) $shop->id, 404);

        $validated = $request->validate([
            'rate_per_gram' => 'required|numeric|min:0.0001|max:999999.9999',
        ]);

        $pricing->saveSameDayOverride($shop, $profile, (float) $validated['rate_per_gram']);

        return redirect()->route('settings.edit', ['tab' => 'pricing'])
            ->with('success', 'Same-day purity override saved and stock repricing has been queued.');
    }

    public function resolveLegacyItem(
        Request $request,
        Item $item,
        ShopPricingService $pricing
    ): RedirectResponse {
        $shop = $request->user()->shop;
        abort_unless($shop && $shop->isRetailer(), 404);
        abort_if((int) $item->shop_id !== (int) $shop->id, 404);

        $validated = $request->validate([
            'metal_type' => ['required', Rule::in(['gold', 'silver'])],
        ]);

        $pricing->resolveLegacyItem($item, $validated['metal_type']);

        return redirect()->route('settings.edit', ['tab' => 'pricing'])
            ->with('success', 'Legacy item pricing metadata updated successfully.');
    }
}

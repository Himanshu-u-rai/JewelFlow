<?php

namespace App\Http\Controllers;

use App\Jobs\RepriceRetailerInventoryJob;
use App\Models\Item;
use App\Models\ShopMetalPurityProfile;
use App\Models\ShopPreferences;
use App\Services\MetalRegistry;
use App\Services\ShopPricingService;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PricingSettingsController extends Controller
{
    public function saveTodayRates(Request $request, ShopPricingService $pricing): RedirectResponse
    {
        $bag = $request->input('context') === 'modal' ? 'pricingModal' : 'pricing';

        $input = $request->all();
        foreach (['gold_24k_rate_per_gram', 'silver_999_rate_per_kg'] as $field) {
            $input[$field] = $this->normalizeRateInput($input[$field] ?? null);
        }

        $validated = Validator::make($input, [
            'gold_24k_rate_per_gram' => ['bail', 'required', 'regex:/^\d+(?:\.\d+)?$/', 'numeric', 'min:0.0001', 'max:999999.9999'],
            'silver_999_rate_per_kg' => ['bail', 'required', 'regex:/^\d+(?:\.\d+)?$/', 'numeric', 'min:0.0001', 'max:999999999.9999'],
        ], [
            'gold_24k_rate_per_gram.regex' => 'Enter a valid gold rate using digits and an optional decimal point.',
            'silver_999_rate_per_kg.regex' => 'Enter a valid silver rate using digits and an optional decimal point.',
        ])->validateWithBag($bag);

        $shop = $request->user()->shop;
        abort_unless($shop && $shop->isRetailer(), 404);

        $pricing->saveTodayBaseRates($shop, (int) $request->user()->id, [
            'gold_24k_rate_per_gram' => (float) $validated['gold_24k_rate_per_gram'],
            'silver_999_rate_per_gram' => round(((float) $validated['silver_999_rate_per_kg']) / 1000, 4),
        ]);

        return redirect()->route('settings.edit', ['tab' => 'pricing'])
            ->with('success', 'Today\'s pricing rates were saved and stock repricing has been queued.');
    }

    /**
     * Accept the way a jeweller actually types a rate.
     *
     * Owners paste or type grouped numbers — "5,500" and, more often here,
     * the Indian grouping "1,00,000". Laravel's `numeric` rejects both, so the
     * save failed with a validation error on input that was perfectly valid to
     * the person entering it. Strip the separators only when the whole string
     * is a well-formed grouped number; anything else is passed through
     * untouched so the regex rule below still rejects it rather than us
     * silently "repairing" a typo like "5,50" into 550.
     */
    private function normalizeRateInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        $westernGrouping = '\d{1,3}(?:,\d{3})+';
        $indianGrouping = '\d{1,2}(?:,\d{2})+,\d{3}';

        if (preg_match('/^(?:'.$westernGrouping.'|'.$indianGrouping.')(?:\.\d+)?$/', $trimmed) === 1) {
            return str_replace(',', '', $trimmed);
        }

        return $trimmed;
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
            'metal_type' => ['required', Rule::in(MetalRegistry::accountingTruthMetals())],
            'code' => 'nullable|string|max:30',
            'label' => 'nullable|string|max:60',
            'purity_value' => 'required|numeric|min:0.001|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $pricing->upsertPurityProfile($shop, array_merge($validated, [
                'is_active' => $request->boolean('is_active', true),
            ]));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'unique') || $e->getCode() === '23000') {
                return back()->withErrors(['purity_value' => 'A profile with this metal type and purity value already exists.'])->withInput();
            }
            throw $e;
        }

        if ($dailyRate = $pricing->currentDailyRate($shop)) {
            $pricing->resolveAndRecordCurrentDayRates($dailyRate, true);
            RepriceRetailerInventoryJob::dispatch((int) $shop->id)->afterCommit();
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
            'metal_type' => ['required', Rule::in(MetalRegistry::accountingTruthMetals())],
            'code' => 'nullable|string|max:30',
            'label' => 'nullable|string|max:60',
            'purity_value' => 'required|numeric|min:0.001|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $pricing->upsertPurityProfile($shop, array_merge($validated, [
                'is_active' => $request->boolean('is_active'),
            ]), $profile);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'unique') || $e->getCode() === '23000') {
                return back()->withErrors(['purity_value' => 'A profile with this metal type and purity value already exists.'])->withInput();
            }
            throw $e;
        }

        if ($dailyRate = $pricing->currentDailyRate($shop)) {
            $pricing->resolveAndRecordCurrentDayRates($dailyRate, true);
            RepriceRetailerInventoryJob::dispatch((int) $shop->id)->afterCommit();
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
            'metal_type' => ['required', Rule::in(MetalRegistry::accountingTruthMetals())],
        ]);

        $pricing->resolveLegacyItem($item, $validated['metal_type']);

        return redirect()->route('settings.edit', ['tab' => 'pricing'])
            ->with('success', 'Legacy item pricing metadata updated successfully.');
    }
}

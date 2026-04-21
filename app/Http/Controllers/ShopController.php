<?php

namespace App\Http\Controllers;

use App\Models\Platform\PlatformSetting;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Services\OnboardingResumeService;
use App\Services\TenantRoleService;
use App\Support\ShopEdition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShopController extends Controller
{
    public function __construct(
        private TenantRoleService $tenantRoleService
    ) {
    }

    /**
     * Shop-type (edition) chooser. Renders a multi-select form; POST stores
     * the chosen edition set in session and moves the user to plan selection.
     *
     * Primary edition precedence when multiple are chosen: retailer > manufacturer > dhiran.
     * The primary is written to users.onboarding_shop_type and shops.shop_type so
     * legacy billing/plan code (Phase 5 work) keeps functioning.
     */
    public function chooseType(Request $request)
    {
        $user = Auth::user();

        if ($user->shop_id) {
            return redirect()->route('dashboard');
        }

        $retailerEnabled     = PlatformSetting::retailerEnabled();
        $manufacturerEnabled = PlatformSetting::manufacturerEnabled();
        $dhiranEnabled       = PlatformSetting::dhiranEnabled();

        $enabledSet = PlatformSetting::enabledShopTypes();

        if (empty($enabledSet)) {
            abort(503, 'Registrations are currently closed.');
        }

        // If only one edition is enabled platform-wide, auto-pick it and skip screen.
        if (count($enabledSet) === 1) {
            $only = $enabledSet[0];
            $this->persistEditionChoice($user, [$only]);
            return redirect()->route('subscription.plans');
        }

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'editions'   => 'required|array|min:1',
                'editions.*' => 'required|string|in:retailer,manufacturer,dhiran',
            ]);

            $chosen = array_values(array_unique($validated['editions']));

            foreach ($chosen as $edition) {
                if (! in_array($edition, $enabledSet, true)) {
                    return back()
                        ->with('error', ucfirst($edition) . ' registration is currently unavailable.')
                        ->withInput();
                }
            }

            $this->persistEditionChoice($user, $chosen);
            return redirect()->route('subscription.plans');
        }

        return view('shops.choose-type', [
            'retailerEnabled'     => $retailerEnabled,
            'manufacturerEnabled' => $manufacturerEnabled,
            'dhiranEnabled'       => $dhiranEnabled,
            'selected'            => session('onboarding_editions', []),
        ]);
    }

    public function create(Request $request)
    {
        $user = Auth::user();

        if ($user->shop_id) {
            return redirect()->route('dashboard');
        }

        $pendingSubscription = OnboardingResumeService::findPendingSubscription($user);
        $hasCompletedPayment = $pendingSubscription !== null || session('subscription_completed');

        if (!$hasCompletedPayment) {
            $step = OnboardingResumeService::resolveStep($user);

            return OnboardingResumeService::redirectForStep($step)
                ->with('error', 'Please complete your subscription first.');
        }

        if ($pendingSubscription) {
            session(['pending_subscription_id' => $pendingSubscription->id]);
            session(['subscription_completed' => true]);
        }

        $editions = session('onboarding_editions');
        if (!is_array($editions) || empty($editions)) {
            $legacy = session('onboarding_shop_type') ?? $user->onboarding_shop_type;
            $editions = $legacy ? [$legacy] : [];
        }

        $editions = array_values(array_intersect(
            ShopEdition::ALL,
            array_unique($editions)
        ));

        if (empty($editions)) {
            return redirect()->route('shops.choose-type');
        }

        $primary = static::primaryEdition($editions);

        return view('shops.create', [
            'shopType' => $primary,
            'editions' => $editions,
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        if ($user->shop_id) {
            return redirect()->route('dashboard');
        }

        $pendingSubscription = OnboardingResumeService::findPendingSubscription($user);
        if (!session('subscription_completed') && !session('pending_subscription_id') && !$pendingSubscription) {
            return redirect()->route('subscription.plans')
                ->with('error', 'Please complete your subscription first.');
        }

        $sessionEditions = session('onboarding_editions');
        if (!is_array($sessionEditions) || empty($sessionEditions)) {
            $legacy = session('onboarding_shop_type') ?? $user->onboarding_shop_type;
            $sessionEditions = $legacy ? [$legacy] : [];
        }
        $sessionEditions = array_values(array_intersect(
            ShopEdition::ALL,
            array_unique($sessionEditions)
        ));

        if (empty($sessionEditions)) {
            return redirect()->route('shops.choose-type');
        }

        $primary = static::primaryEdition($sessionEditions);

        $baseRules = [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|digits:10',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'pincode' => 'required|string|digits:6',
            'country' => 'nullable|string|max:100',
            'gst_number' => 'nullable|string|max:50',
            'owner_first_name' => 'required|string|max:255',
            'owner_last_name' => 'required|string|max:255',
            'owner_mobile' => 'required|string|digits:10',
            'owner_email' => 'nullable|email',
        ];

        if (in_array(ShopEdition::MANUFACTURER, $sessionEditions, true)) {
            $baseRules['gst_rate'] = 'required|numeric|min:0|max:100';
            $baseRules['wastage_recovery_percent'] = 'required|numeric|min:0|max:100';
        }

        $data = $request->validate($baseRules);

        $data['shop_type'] = $primary;

        $data['address'] = trim(
            $data['address_line1'] . ', ' .
            ($data['address_line2'] ? $data['address_line2'] . ', ' : '') .
            $data['city'] . ', ' .
            $data['state'] . ' - ' .
            $data['pincode'] .
            ($data['country'] ? ', ' . $data['country'] : '')
        );
        $data['country'] = $data['country'] ?? 'India';

        if (! in_array(ShopEdition::MANUFACTURER, $sessionEditions, true)) {
            $data['gst_rate'] = 3.0;
            $data['wastage_recovery_percent'] = 0;
        }

        $shop = DB::transaction(function () use ($data, $sessionEditions, $user, $pendingSubscription) {
            $shop = Shop::create($data);

            // Shop::booted() already grants the primary shop_type. Grant all
            // additional editions here so multi-edition shops are fully
            // provisioned atomically with creation.
            foreach ($sessionEditions as $edition) {
                ShopEdition::grantTo($shop, $edition, $user);
            }

            if (in_array(ShopEdition::DHIRAN, $sessionEditions, true)) {
                \App\Models\Dhiran\DhiranSettings::getForShop($shop->id)
                    ->update(['is_enabled' => true]);
            }

            $roles = $this->tenantRoleService->ensureDefaultsForShop($shop->id);
            $ownerRole = $roles['owner'] ?? null;

            $user->forceFill([
                'shop_id' => $shop->id,
                'role_id' => $ownerRole?->id,
            ])->save();

            $subscriptionId = session('pending_subscription_id') ?? $pendingSubscription?->id;
            if ($subscriptionId) {
                ShopSubscription::where('id', $subscriptionId)
                    ->whereNull('shop_id')
                    ->where('user_id', $user->id)
                    ->update(['shop_id' => $shop->id]);
            }

            $shop->forceFill([
                'access_mode' => 'active',
                'is_active' => true,
            ])->save();

            return $shop;
        });

        OnboardingResumeService::clearOnboarding($user);
        session()->forget([
            'onboarding_shop_type',
            'onboarding_editions',
            'pending_plan_id',
            'pending_billing_cycle',
            'subscription_completed',
            'pending_subscription_id',
            'razorpay_order_id',
        ]);

        return redirect()->route('dashboard')
            ->with('success', 'Welcome to JewelFlow! Your shop is ready.');
    }

    /**
     * Pick the primary edition from a chosen set. Retailer > Manufacturer > Dhiran.
     * Used to populate the legacy shops.shop_type column and any read-sites that
     * still expect a single edition value.
     */
    public static function primaryEdition(array $editions): string
    {
        foreach ([ShopEdition::RETAILER, ShopEdition::MANUFACTURER, ShopEdition::DHIRAN] as $preferred) {
            if (in_array($preferred, $editions, true)) {
                return $preferred;
            }
        }
        return ShopEdition::RETAILER;
    }

    private function persistEditionChoice($user, array $editions): void
    {
        $primary = static::primaryEdition($editions);

        OnboardingResumeService::setStep($user, OnboardingResumeService::STEP_SELECT_PLAN, $primary);

        session([
            'onboarding_shop_type' => $primary,
            'onboarding_editions'  => array_values($editions),
        ]);
    }
}

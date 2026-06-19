<?php

namespace App\Http\Controllers;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Services\OnboardingResumeService;
use App\Services\TenantRoleService;
use App\Support\Realm;
use App\Support\ShopEdition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dhiran (gold-loan product) customer onboarding — Phase 3.
 *
 * A Dhiran-realm customer who has registered on dhiran.jewelflows.com follows a
 * Dhiran-only path: choose the Dhiran yearly plan → pay (the shared, product-
 * agnostic billing engine) → create a Dhiran business → land on the Dhiran
 * dashboard. The customer NEVER sees the ERP shop-type chooser, ERP plans, or
 * ERP inventory/POS setup.
 *
 * This controller reuses the existing infrastructure rather than duplicating it:
 *  - Plan / PlatformProduct / edition mapping (Plan::grantsEdition()).
 *  - The shared payment flow (SubscriptionController@payment / initiatePayment /
 *    paymentCallback) — it is product-agnostic and grants the plan's own edition.
 *  - OnboardingResumeService for the persisted step + pending-subscription lookup.
 *  - ShopEdition::grantTo for the dhiran edition grant (same path ShopController
 *    uses), so a Dhiran shop is provisioned exactly like any other, minus the ERP
 *    edition.
 *
 * The only Dhiran-specific decisions here: seed onboarding_shop_type='dhiran' so
 * the shared plan/payment screens scope to Dhiran, and create the shop with the
 * dhiran edition ONLY (never retailer/manufacturer).
 */
class DhiranOnboardingController extends Controller
{
    public function __construct(
        private readonly TenantRoleService $tenantRoleService,
    ) {}

    /** Guard: every action here is for an authenticated Dhiran-realm user. */
    private function dhiranUserOrRedirect(): ?\App\Models\User
    {
        $user = Auth::user();

        // Not a Dhiran account → it does not belong in this flow at all.
        if (! $user || ! $user->isDhiran()) {
            return null;
        }

        return $user;
    }

    /**
     * The Dhiran yearly plan. Pay-only for now (no monthly, no trial button) —
     * resolved by edition, not code, so it stays correct if the plan code changes.
     */
    private function dhiranYearlyPlan(): ?Plan
    {
        return Plan::whereRaw('is_active IS TRUE')
            ->with('platformProduct')
            ->get()
            ->first(fn (Plan $plan) => $plan->grantsEdition() === ShopEdition::DHIRAN
                && ! is_null($plan->price_yearly));
    }

    /**
     * Dhiran plan selection. Shows ONLY the Dhiran yearly plan — never ERP or
     * manufacturing plans, never a multi-product chooser.
     */
    public function plans(Request $request)
    {
        $user = $this->dhiranUserOrRedirect();
        if (! $user) {
            return redirect('/dashboard');
        }

        // Already onboarded → straight to the Dhiran dashboard.
        if ($user->shop_id) {
            return redirect()->route('dhiran.dashboard');
        }

        // Already paid (pending subscription waiting for a shop) → go create the
        // Dhiran business.
        if (OnboardingResumeService::findPendingSubscription($user)) {
            return redirect()->route('dhiran.onboarding');
        }

        $plan = $this->dhiranYearlyPlan();
        if (! $plan) {
            return redirect()->route('login')
                ->with('error', 'Dhiran plans are not available right now. Please contact support.');
        }

        // Seed the shared onboarding state so the reused payment screens scope to
        // the Dhiran product (and never auto-correct to a retail type).
        OnboardingResumeService::setStep($user, OnboardingResumeService::STEP_SELECT_PLAN, ShopEdition::DHIRAN);
        session([
            'onboarding_shop_type' => ShopEdition::DHIRAN,
            'onboarding_editions'  => [ShopEdition::DHIRAN],
        ]);

        return view('dhiran.onboarding.plans', [
            'plan' => $plan,
        ]);
    }

    /**
     * Choose the Dhiran plan → hand off to the shared payment flow. Yearly only.
     * We store the pending plan in session exactly like SubscriptionController@
     * choosePlan, then send the user to the (Dhiran-aware) payment page.
     */
    public function subscribe(Request $request)
    {
        $user = $this->dhiranUserOrRedirect();
        if (! $user) {
            return redirect('/dashboard');
        }

        $plan = $this->dhiranYearlyPlan();
        if (! $plan) {
            return redirect()->route('dhiran.plans')
                ->with('error', 'Dhiran plans are not available right now.');
        }

        session([
            'pending_plan_id'       => $plan->id,
            'pending_billing_cycle' => 'yearly',
            'onboarding_shop_type'  => ShopEdition::DHIRAN,
            'onboarding_editions'   => [ShopEdition::DHIRAN],
        ]);

        OnboardingResumeService::setStep($user, OnboardingResumeService::STEP_PAYMENT, ShopEdition::DHIRAN);

        // Reuse the shared payment screen + Razorpay flow. It is product-agnostic:
        // it reads pending_plan_id / pending_billing_cycle from session and grants
        // the plan's OWN edition (dhiran) on a successful callback.
        return redirect()->route('subscription.payment');
    }

    /**
     * Dhiran business creation form. Only reached once a Dhiran subscription is
     * paid/pending. Dhiran-specific, simple fields — no POS / inventory / GST-rate
     * / job-order / shop-type setup.
     */
    public function onboarding(Request $request)
    {
        $user = $this->dhiranUserOrRedirect();
        if (! $user) {
            return redirect('/dashboard');
        }

        if ($user->shop_id) {
            return redirect()->route('dhiran.dashboard');
        }

        // Must have completed (or be mid-) payment first.
        if (! session('subscription_completed')
            && ! session('pending_subscription_id')
            && ! OnboardingResumeService::findPendingSubscription($user)
        ) {
            return redirect()->route('dhiran.plans')
                ->with('error', 'Please choose your Dhiran plan first.');
        }

        return view('dhiran.onboarding.create', [
            'user' => $user,
        ]);
    }

    /**
     * Persist the Dhiran business. Creates shops.shop_type = 'dhiran', grants ONLY
     * the dhiran edition, links the paid subscription, enables Dhiran settings, and
     * lands the owner on the Dhiran dashboard. Mirrors ShopController@store but is
     * Dhiran-scoped — it never grants retailer/manufacturer.
     */
    public function store(Request $request)
    {
        $user = $this->dhiranUserOrRedirect();
        if (! $user) {
            return redirect('/dashboard');
        }

        if ($user->shop_id) {
            return redirect()->route('dhiran.dashboard');
        }

        $pendingSubscription = OnboardingResumeService::findPendingSubscription($user);
        if (! session('subscription_completed')
            && ! session('pending_subscription_id')
            && ! $pendingSubscription
        ) {
            return redirect()->route('dhiran.plans')
                ->with('error', 'Please complete your Dhiran subscription first.');
        }

        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'owner_name'     => 'required|string|max:255',
            'phone'          => 'required|string|digits:10',
            'address'        => 'required|string|max:500',
            'city'           => 'required|string|max:100',
            'state'          => 'required|string|max:100',
            'gst_number'          => 'nullable|string|max:50',
            'loan_number_prefix'  => 'nullable|string|max:12',
        ]);

        // shops stores owner name as first/last + a separate owner mobile (all
        // NOT NULL). Dhiran asks for one "owner name" + the business phone, so we
        // split the name and reuse the phone as the owner mobile.
        $ownerName  = trim($data['owner_name']);
        $nameParts  = preg_split('/\s+/', $ownerName, 2);
        $ownerFirst = $nameParts[0] ?? $ownerName;
        $ownerLast  = $nameParts[1] ?? '';

        $shop = DB::transaction(function () use ($data, $user, $pendingSubscription, $ownerName, $ownerFirst, $ownerLast) {
            $shop = Shop::create([
                'name'         => $data['name'],
                'shop_type'    => ShopEdition::DHIRAN,
                'phone'        => $data['phone'],
                'address'      => $data['address'],
                'city'         => $data['city'],
                'state'        => $data['state'],
                'country'      => 'India',
                'gst_number'   => $data['gst_number'] ?? null,
                'owner_first_name' => $ownerFirst,
                'owner_last_name'  => $ownerLast,
                'owner_mobile'     => $data['phone'],
                // Dhiran is a service product, not jewellery retail. These ERP
                // columns are NOT NULL on shops; set safe neutral defaults rather
                // than asking a Dhiran owner ERP questions.
                'gst_rate'                 => 3.0,
                'wastage_recovery_percent' => 0,
            ]);

            // Grant ONLY the dhiran edition — never retailer/manufacturer.
            ShopEdition::grantTo($shop, ShopEdition::DHIRAN, $user);

            // Enable the Dhiran module + apply an optional loan-receipt prefix.
            $settings = DhiranSettings::getForShop($shop->id);
            $settingsUpdate = ['is_enabled' => true];
            if (! empty($data['loan_number_prefix'])) {
                $settingsUpdate['loan_number_prefix'] = $data['loan_number_prefix'];
            }
            $settings->update($settingsUpdate);

            $roles = $this->tenantRoleService->ensureDefaultsForShop($shop->id);
            $ownerRole = $roles['owner'] ?? null;

            $user->forceFill([
                'shop_id' => $shop->id,
                'role_id' => $ownerRole?->id,
                'name'    => $ownerName,
            ])->save();

            // Link the paid/pending subscription to the new shop and grant its
            // edition + issue the deferred invoice — same path as ShopController.
            $subscriptionId = session('pending_subscription_id') ?? $pendingSubscription?->id;
            if ($subscriptionId) {
                ShopSubscription::where('id', $subscriptionId)
                    ->whereNull('shop_id')
                    ->where('user_id', $user->id)
                    ->update(['shop_id' => $shop->id]);

                $subscription = ShopSubscription::find($subscriptionId);
                if ($subscription && $subscription->shop_id) {
                    app(\App\Services\SubscriptionPaymentService::class)
                        ->grantEditionForSubscription($subscription);

                    if ((float) $subscription->price_paid > 0
                        && ! \App\Models\Platform\PlatformInvoice::where('shop_subscription_id', $subscription->id)->exists()
                    ) {
                        $invoice = app(\App\Services\PlatformInvoiceService::class)
                            ->issueForSubscription($subscription);
                        dispatch(new \App\Jobs\SendPlatformInvoiceEmail($invoice->id));
                    }
                }
            }

            $shop->forceFill([
                'access_mode' => 'active',
                'is_active'   => true,
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

        return redirect()->route('dhiran.dashboard')
            ->with('success', 'Welcome to Dhiran! Your gold-loan business is ready.');
    }
}

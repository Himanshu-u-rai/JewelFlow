<?php

namespace App\Http\Controllers;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformSetting;
use App\Models\Platform\ShopSubscription;
use App\Services\OnboardingResumeService;
use App\Services\SubscriptionPaymentService;
use App\Services\SubscriptionWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Razorpay\Api\Errors\SignatureVerificationError;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionPaymentService $paymentService,
        private readonly SubscriptionWebhookService $webhookService,
    ) {}

    public static function featureLabels(): array
    {
        return [
            'pos' => 'Point of Sale',
            'inventory' => 'Inventory Management',
            'customers' => 'Customer Management',
            'repairs' => 'Repair Tracking',
            'invoices' => 'Invoicing & Billing',
            'reports' => 'Business Reports',
            'vendors' => 'Vendor Management',
            'schemes' => 'Gold Saving Schemes',
            'loyalty' => 'Loyalty Points',
            'installments' => 'EMI / Installments',
            'reorder_alerts' => 'Reorder Alerts',
            'tag_printing' => 'Tag Printing',
            'whatsapp_catalog' => 'Catalog',
            'bulk_imports' => 'Bulk Imports (CSV)',
            'gold_inventory' => 'Gold Lot Inventory',
            'manufacturing' => 'Manufacturing Workflow',
            'customer_gold' => 'Customer Gold Ledger',
            'exchange' => 'Gold Exchange',
            'public_catalog' => 'Public Item Catalog',
            'staff_limit' => 'Staff Accounts',
            'max_items' => 'Item Limit',
        ];
    }

    public function showPlans()
    {
        $user = Auth::user();
        $shopType = $user->shop?->shop_type ?? session('onboarding_shop_type') ?? $user->onboarding_shop_type;

        // A shop on a TRIAL may upgrade early (it keeps its free days; the paid
        // term begins when the trial ends). Only a shop that already holds a live
        // PAID subscription is blocked from buying again.
        if ($this->hasLivePaidSubscription()) {
            return redirect()->route('subscription.status')
                ->with('error', 'Your shop already has an active paid subscription.');
        }

        // If user already has a pending (paid) subscription, skip to shop creation
        if (!$user->shop_id && OnboardingResumeService::findPendingSubscription($user)) {
            return redirect()->route('shops.create')
                ->with('success', 'You already have an active subscription. Please set up your shop.');
        }

        // Enforce enabled shop types — only for new users (no existing shop)
        $enabledTypes = PlatformSetting::enabledShopTypes();
        if (!$user->shop_id && (!$shopType || !in_array($shopType, $enabledTypes, true))) {
            $onlyType = PlatformSetting::onlyEnabledType();
            if ($onlyType) {
                // Auto-correct and persist
                $shopType = $onlyType;
                OnboardingResumeService::setStep(Auth::user(), OnboardingResumeService::STEP_SELECT_PLAN, $shopType);
                session(['onboarding_shop_type' => $shopType]);
            } else {
                // Both enabled but no type chosen yet
                return redirect()->route('shops.choose-type')
                    ->with('error', 'Please select your business type first.');
            }
        }

        $allPlans = Plan::whereRaw('is_active IS TRUE')
            ->with('platformProduct')
            ->orderBy('price_monthly')
            ->get();

        // Product-scoped selection: show the plans for the chosen edition's
        // product. grantsEdition() resolves plan → edition string via the linked
        // platform product (with a code-prefix fallback), so this is explicit
        // and correct — and Dhiran is selectable without any retail shop_type.
        $plans = $allPlans->filter(
            fn ($plan) => $plan->grantsEdition() === $shopType
        )->values();

        if ($plans->isEmpty()) {
            return redirect()->route('shops.choose-type')
                ->with('error', 'No plans are available for your business type. Please contact support.');
        }

        $monthlyPlan = $plans->first(fn ($plan) => is_null($plan->price_yearly));
        $yearlyPlan = $plans->first(fn ($plan) => !is_null($plan->price_yearly));
        $featureLabels = self::featureLabels();

        // Trial length shown to the owner MUST match what startTrial() actually
        // grants (the admin-configurable PlatformSetting), not the unused
        // plan->trial_days column or a hardcoded "1 month".
        $trialDays = PlatformSetting::trialDays();

        // If the shop is reaching this page mid-trial, it's an EARLY UPGRADE: the
        // free trial keeps running and the paid term begins when the trial ends.
        // Pass the trial end date so the view can reassure the owner no days are lost.
        $current = $this->currentSubscription();
        $upgradingFromTrial = $current && $current->status === 'trial';
        $trialEndsAt = $upgradingFromTrial ? $current->ends_at : null;

        return view('subscription.plans', compact(
            'plans',
            'shopType',
            'monthlyPlan',
            'yearlyPlan',
            'featureLabels',
            'trialDays',
            'upgradingFromTrial',
            'trialEndsAt'
        ));
    }

    /**
     * The shop's latest subscription, or null. Single read used by the gates.
     */
    private function currentSubscription(): ?ShopSubscription
    {
        $shopId = Auth::user()?->shop_id;
        if (! $shopId) {
            return null;
        }

        return ShopSubscription::query()
            ->where('shop_id', $shopId)
            ->latest('id')
            ->first();
    }

    /**
     * Live PAID subscription states — a shop in one of these has already paid
     * for a current term and must NOT be allowed to start another purchase
     * (no double-charge, no stacked term). A `trial` is NOT here on purpose:
     * a trialing shop may upgrade early (it keeps its free days; the paid term
     * begins when the trial ends — see SubscriptionPaymentService::createSubscription).
     */
    private const LIVE_PAID_STATUSES = ['active', 'grace', 'read_only'];

    /** True when the shop already holds a live paid subscription (blocks buying). */
    private function hasLivePaidSubscription(): bool
    {
        $sub = $this->currentSubscription();

        return $sub !== null && in_array($sub->status, self::LIVE_PAID_STATUSES, true);
    }

    public function choosePlan(Request $request)
    {
        if ($this->hasLivePaidSubscription()) {
            return redirect()->route('subscription.status')
                ->with('error', 'Your shop already has an active paid subscription.');
        }

        $validated = $request->validate([
            'plan_id' => [
                'required',
                Rule::exists('plans', 'id')->where(fn ($query) => $query->whereRaw('is_active IS TRUE')),
            ],
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        session([
            'pending_plan_id' => $validated['plan_id'],
            'pending_billing_cycle' => $validated['billing_cycle'],
        ]);

        // Persist step to DB
        OnboardingResumeService::setStep(Auth::user(), OnboardingResumeService::STEP_PAYMENT);

        return redirect()->route('subscription.payment');
    }

    /**
     * Start a free trial — no payment. Creates a trial subscription that grants
     * the product's edition and routes the owner straight to shop creation
     * (onboarding) or the dashboard. At trial end the scheduler drops the shop
     * to read-only; the owner buys a plan to continue.
     */
    public function startTrial(Request $request)
    {
        $user = Auth::user();

        // Already has a live subscription? Don't start a trial on top of it.
        if ($user?->shop_id) {
            $current = ShopSubscription::query()
                ->where('shop_id', $user->shop_id)
                ->latest('id')
                ->first();
            if ($current && in_array($current->status, ['active', 'trial', 'grace', 'read_only'], true)) {
                return redirect()->route('subscription.status')
                    ->with('error', 'Your shop already has an active subscription.');
            }
        }

        $validated = $request->validate([
            'plan_id' => [
                'required',
                Rule::exists('plans', 'id')->where(fn ($q) => $q->whereRaw('is_active IS TRUE')),
            ],
        ]);

        $plan = Plan::whereRaw('is_active IS TRUE')->find($validated['plan_id']);
        if (! $plan) {
            return redirect()->route('subscription.plans')
                ->with('error', 'That plan is no longer available.');
        }

        try {
            $subscription = $this->paymentService->startTrial($plan);
        } catch (\LogicException $e) {
            // Already-trialed-this-product, or unlinked plan.
            return redirect()->route('subscription.plans')->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Trial start failed', ['user_id' => $user?->id, 'plan_id' => $plan->id, 'error' => $e->getMessage()]);
            return redirect()->route('subscription.plans')
                ->with('error', 'Could not start your free trial. Please try again or contact support.');
        }

        session([
            'pending_subscription_id' => $subscription->id,
            'subscription_completed'  => true,
        ]);
        session()->forget(['pending_plan_id', 'pending_billing_cycle', 'razorpay_order_id']);

        OnboardingResumeService::setStep($user, OnboardingResumeService::STEP_CREATE_SHOP);

        if (! $user->shop_id) {
            $shopType = session('onboarding_shop_type') ?? $user->onboarding_shop_type ?? 'retailer';
            return redirect()->route('shops.create', ['type' => $shopType])
                ->with('success', 'Your free trial has started! Now set up your shop details.');
        }

        return redirect()->route('dashboard')
            ->with('success', 'Your free trial has started.');
    }

    public function payment()
    {
        // Trial shops may proceed to pay (early upgrade); only live paid shops are blocked.
        if ($this->hasLivePaidSubscription()) {
            return redirect()->route('subscription.status')
                ->with('error', 'Your shop already has an active paid subscription.');
        }

        $planId = session('pending_plan_id');
        $billingCycle = session('pending_billing_cycle');

        if (!$planId || !$billingCycle) {
            return redirect()->route('subscription.plans')
                ->with('error', 'Please select a plan first.');
        }

        $plan = Plan::whereRaw('is_active IS TRUE')->find($planId);
        if (!$plan) {
            return redirect()->route('subscription.plans')
                ->with('error', 'Selected plan is no longer available.');
        }

        $price = $billingCycle === 'yearly'
            ? $plan->price_yearly
            : $plan->price_monthly;

        if (is_null($price) || (float) $price <= 0) {
            Log::error('Subscription payment page received invalid plan price.', [
                'plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'billing_cycle' => $billingCycle,
                'price' => $price,
                'user_id' => Auth::id(),
            ]);

            return redirect()->route('subscription.plans')
                ->with('error', 'Selected plan has invalid pricing. Please choose another plan or contact support.');
        }

        $shopType = Auth::user()->shop?->shop_type ?? session('onboarding_shop_type') ?? Auth::user()->onboarding_shop_type;
        $featureLabels = self::featureLabels();
        $isTestMode = str_starts_with(config('services.razorpay.key_id', ''), 'rzp_test_');

        return view('subscription.payment', compact(
            'plan',
            'billingCycle',
            'price',
            'shopType',
            'featureLabels',
            'isTestMode'
        ));
    }

    public function initiatePayment(Request $request)
    {
        // Defence in depth: this endpoint previously had no subscription gate and
        // relied on the upstream pages. Block a live PAID shop from creating a
        // Razorpay order here directly (a trial shop is allowed — early upgrade).
        if ($this->hasLivePaidSubscription()) {
            return response()->json([
                'error' => 'Your shop already has an active paid subscription.',
                'redirect' => route('subscription.status'),
            ], 422);
        }

        // Only trust session values set by choosePlan() — never accept from request body
        $planId = session('pending_plan_id');
        $billingCycle = session('pending_billing_cycle');

        // DB fallback: check onboarding state if session lost
        if (!$planId || !$billingCycle) {
            $user = Auth::user();
            $shopType = $user->onboarding_shop_type ?? session('shop_type');
            if ($shopType) {
                $latestPlan = Plan::whereRaw('is_active IS TRUE')
                    ->where('code', 'like', $shopType . '_%')
                    ->latest('id')
                    ->first();
                if ($latestPlan) {
                    $planId = $planId ?? $latestPlan->id;
                    $billingCycle = $billingCycle ?? ($latestPlan->price_yearly ? 'yearly' : 'monthly');
                }
            }
        }

        if (!$planId || !$billingCycle) {
            return response()->json([
                'error' => 'Session expired. Please select a plan again.',
                'redirect' => route('subscription.plans'),
            ], 422);
        }

        $plan = Plan::whereRaw('is_active IS TRUE')->find($planId);
        if (!$plan) {
            return response()->json(['error' => 'Plan not available.'], 422);
        }

        $price = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        if (is_null($price) || (float) $price <= 0) {
            Log::error('Subscription initiatePayment blocked due to invalid plan price.', [
                'plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'billing_cycle' => $billingCycle,
                'price' => $price,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => 'Selected plan has invalid pricing. Please choose a valid plan.',
                'redirect' => route('subscription.plans'),
            ], 422);
        }

        $user = Auth::user();

        try {
            $order = $this->paymentService->createRazorpayOrder($plan, $billingCycle);

            session(['razorpay_order_id' => $order->id]);

            return response()->json([
                'order_id' => $order->id,
                'amount' => (int) round($price * 100),
                'currency' => 'INR',
                'key_id' => config('services.razorpay.key_id'),
                'plan_name' => $plan->name,
                'user_name' => $user->name ?? $user->mobile_number,
                'user_email' => $user->email ?? '',
                'user_contact' => $user->mobile_number ?? '',
            ]);
        } catch (\Exception $e) {
            Log::error('Razorpay order creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'error' => 'Payment initiation failed. Please try again.',
            ], 500);
        }
    }

    public function paymentCallback(Request $request)
    {
        $paymentId = $request->input('razorpay_payment_id');
        $orderId = $request->input('razorpay_order_id');
        $signature = $request->input('razorpay_signature');

        if (!$paymentId || !$orderId || !$signature) {
            return redirect()->route('subscription.payment')
                ->with('error', 'Payment verification failed. Please try again.');
        }

        // Step 1: Verify payment signature
        try {
            $this->paymentService->verifyPaymentSignature($orderId, $paymentId, $signature);
        } catch (SignatureVerificationError $e) {
            Log::error('Razorpay signature verification failed', [
                'payment_id' => $paymentId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('subscription.payment')
                ->with('error', 'Payment signature invalid. Contact support with ref: ' . $paymentId);
        }

        // Step 2: Fetch order and validate plan data from Razorpay notes
        try {
            $orderData = $this->paymentService->fetchAndValidateOrder($orderId);
        } catch (\Exception $e) {
            Log::error('Failed to fetch/validate Razorpay order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return redirect()->route('subscription.payment')
                ->with('error', 'Could not verify payment. Contact support with ref: ' . $paymentId);
        }

        $rzpOrder = $orderData['order'];
        $plan = $orderData['plan'];
        $billingCycle = $orderData['billing_cycle'];

        // Step 3: Verify amount matches plan price
        try {
            $this->paymentService->verifyAmount($rzpOrder, $plan, $billingCycle);
        } catch (\Exception $e) {
            return redirect()->route('subscription.payment')
                ->with('error', 'Payment amount mismatch. Contact support with ref: ' . $paymentId);
        }

        // Step 4: Verify payment is actually captured
        try {
            $this->paymentService->verifyPaymentCaptured($paymentId);
        } catch (\Exception $e) {
            return redirect()->route('subscription.payment')
                ->with('error', 'Payment not confirmed. Contact support with ref: ' . $paymentId);
        }

        // Step 5: Idempotency check
        $existingSubscription = $this->paymentService->findExistingSubscription($paymentId);
        if ($existingSubscription) {
            Log::info('Duplicate payment callback detected, returning existing subscription.', [
                'payment_id' => $paymentId,
                'subscription_id' => $existingSubscription->id,
            ]);

            session(['pending_subscription_id' => $existingSubscription->id, 'subscription_completed' => true]);
            OnboardingResumeService::setStep(Auth::user(), OnboardingResumeService::STEP_CREATE_SHOP);

            if (!Auth::user()->shop_id) {
                $shopType = session('onboarding_shop_type') ?? Auth::user()->onboarding_shop_type ?? 'retailer';
                return redirect()->route('shops.create', ['type' => $shopType])
                    ->with('success', 'Payment already processed. Please set up your shop details.');
            }

            return redirect()->route('dashboard')->with('success', 'Subscription already active!');
        }

        // Step 6: Create subscription + event in a single transaction
        $expectedPrice = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        try {
            $subscription = $this->paymentService->createSubscription(
                $plan, $billingCycle, (float) $expectedPrice, $paymentId, $orderId
            );
        } catch (\LogicException $e) {
            // Anti-stacking guard fired: the shop already holds a live paid term,
            // yet a captured payment reached here (a true race — the upstream
            // gates block this normally). Money WAS taken; log loudly so support
            // can refund. We deliberately do NOT create a second paid term.
            Log::critical('Captured payment rejected by anti-stacking guard — manual refund required', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'user_id' => Auth::id(),
                'shop_id' => Auth::user()?->shop_id,
                'reason' => $e->getMessage(),
            ]);

            return redirect()->route('subscription.status')
                ->with('error', 'Your shop already has an active paid subscription, so this payment was not applied. '
                    . 'Please contact support for a refund with ref: ' . $paymentId);
        } catch (\Exception $e) {
            return redirect()->route('subscription.payment')
                ->with('error', $e->getMessage() === 'Platform configuration incomplete.'
                    ? 'Platform configuration incomplete. Please contact support.'
                    : 'Could not create subscription. Contact support with ref: ' . $paymentId);
        }

        // Persist to both session and DB
        session([
            'pending_subscription_id' => $subscription->id,
            'subscription_completed' => true,
        ]);
        session()->forget([
            'pending_plan_id',
            'pending_billing_cycle',
            'razorpay_order_id',
        ]);

        // Update onboarding step in DB (survives browser close)
        OnboardingResumeService::setStep(Auth::user(), OnboardingResumeService::STEP_CREATE_SHOP);

        if (!Auth::user()->shop_id) {
            $shopType = session('onboarding_shop_type') ?? Auth::user()->onboarding_shop_type ?? 'retailer';

            return redirect()->route('shops.create', ['type' => $shopType])
                ->with('success', 'Payment successful! Now set up your shop details.');
        }

        return redirect()->route('dashboard')
            ->with('success', 'Subscription activated successfully!');
    }

    public function webhook(Request $request)
    {
        $body = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');

        try {
            $this->webhookService->verifySignature($body, $signature);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'rejected', 'reason' => 'webhook secret not configured'], 500);
        } catch (\Exception $e) {
            Log::warning('Razorpay webhook invalid signature');
            return response()->json(['status' => 'rejected'], 400);
        }

        $payload = json_decode($body, true);
        $event = $payload['event'] ?? '';

        Log::info('Razorpay webhook', ['event' => $event]);

        match ($event) {
            'payment.captured' => $this->webhookService->handlePaymentCaptured($payload),
            'payment.failed' => $this->webhookService->handlePaymentFailed($payload),
            'refund.created' => $this->webhookService->handleRefundCreated($payload),
            default => Log::info('Webhook: unhandled event', ['event' => $event]),
        };

        return response()->json(['status' => 'ok']);
    }

    public function status()
    {
        // The subscription status display now lives inside the Settings tab
        // system as the "Plan & Billing" tab. The data is loaded by
        // SettingsController::edit(); this method only forwards the old
        // /subscription URL (and any bookmark) to the new tab. Payment flows
        // (showPlans/choosePlan/payment/initiatePayment/paymentCallback/webhook)
        // are unchanged.
        return redirect()->route('settings.edit', ['tab' => 'subscription']);
    }
}

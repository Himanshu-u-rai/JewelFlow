<?php

namespace App\Http\Controllers;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\PlatformSetting;
use App\Models\Platform\ShopSubscription;
use App\Services\OnboardingResumeService;
use App\Services\SubscriptionPaymentService;
use App\Services\SubscriptionWebhookService;
use App\Support\SubscriptionRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $this->abortUnlessOwnerOrOnboarding();

        $user = Auth::user();
        $shopType = $user->shop?->shop_type ?? session('onboarding_shop_type') ?? $user->onboarding_shop_type;

        // A shop on a TRIAL may upgrade early (it keeps its free days; the paid
        // term begins when the trial ends), and a LAPSED shop must always be able
        // to reach this page — renewal is its only way out. See blocksNewPaidTerm().
        if ($this->blocksNewPaidTerm()) {
            return $this->purchaseBlockedResponse();
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

        // Automatic-trial eligibility comes from the SERVICE — the same call
        // startTrial() makes — so the card the owner is shown and the POST the
        // server will honour are one decision, not two that drift apart.
        // `$upgradingFromTrial` is NOT a proxy for it: that flag only means
        // "mid-trial right now", which an expired or cancelled trial — and any
        // lapsed paid term — trivially passes. The view must not re-derive this.
        $trialEligible = $this->paymentService->canStartAutomaticTrial($yearlyPlan ?? $monthlyPlan);

        return view('subscription.plans', compact(
            'plans',
            'shopType',
            'monthlyPlan',
            'yearlyPlan',
            'featureLabels',
            'trialDays',
            'upgradingFromTrial',
            'trialEndsAt',
            'trialEligible'
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
     * The ONE purchase gate, shared by every entry point on this controller so
     * the plan picker, the plan choice, the payment page and the order-creation
     * endpoint can never disagree about whether this shop may buy.
     *
     * Delegates to ShopSubscription::blocksNewPaidTerm(), which blocks exactly
     * two things: a JewelFlows administrator restriction (money must not buy
     * its way out of a compliance hold) and a term that still covers today (no
     * double-charge, no stacked term).
     *
     * This replaces the old status-list check, which treated a legacy
     * `read_only` row as a live paid term and so left a lapsed shop with no way
     * to renew — the very dead end this P0 exists to remove. It also fixes the
     * mirror-image hole: none of these sites previously checked the
     * administrative discriminator except initiatePayment().
     *
     * No shop yet (onboarding) means nothing to duplicate and no hold to
     * respect, so the purchase proceeds.
     */
    private function blocksNewPaidTerm(): bool
    {
        $shop = Auth::user()?->shop;
        if (! $shop) {
            return false;
        }

        return ShopSubscription::blocksNewPaidTerm($this->currentSubscription(), $shop);
    }

    /**
     * Subscription COMMERCE is the shop owner's alone. A cashier must not be able
     * to pick a plan, open checkout, create a Razorpay order, start a trial, or
     * read the shop's PlatformInvoice history off the status page.
     *
     * This lives in the controller rather than as `role:owner` route middleware
     * because RoleMiddleware redirects any user with a null shop_id to
     * shops.create, and plan selection comes BEFORE shop creation in onboarding —
     * a blanket route guard would bounce every new signup out of the funnel. The
     * guard therefore bites only once a shop (and therefore a role) exists, which
     * is exactly when "staff" becomes a meaningful concept.
     *
     * isShopOwner() is the tenant-safe predicate (unscoped role read plus an
     * explicit shop_id ownership match), so this is correct on the payment routes
     * too, which are bypass-listed by both middlewares.
     *
     * IT FAILS CLOSED. Ownership must be PROVEN; absence of evidence is not
     * evidence. An earlier cut returned early on `role_id === null`, arguing that
     * a role-less user could only be an owner whose role assignment did not land,
     * because "staff always have a role". That premise is false: the RBAC
     * migration (2026_02_04_100000_create_rbac_tables) added users.role_id as
     * NULLABLE and dropped the old users.role string WITHOUT backfilling, so every
     * user predating it — cashiers included — carries role_id = NULL. The early
     * return handed those legacy cashiers plan selection, checkout, Razorpay order
     * creation, trial start and the shop's PlatformInvoice history.
     *
     * OWNERSHIP IS NOT INFERRED FROM users.mobile_number. Equality with
     * shops.owner_mobile was considered and rejected: the columns are
     * independently writable and legitimately diverge. MobileChangeController::
     * confirm() and Admin\UserMobileController::update() both rewrite
     * users.mobile_number without touching shops.owner_mobile, and
     * SettingsController rewrites shops.owner_mobile while syncing only the
     * owner's NAME back — "the two are independent (login identity vs registered
     * shop owner)". So equality would deny a real owner who changed their login
     * mobile, and would promote a cashier whose login happens to match a stale or
     * transferred owner_mobile. Neither direction is proof.
     *
     * A legacy role-less OWNER is repaired by granting them the owner role, which
     * is a separate follow-up — not a hole left open in the payment boundary.
     */
    private function abortUnlessOwner(): void
    {
        if (! Auth::user()?->isShopOwner()) {
            abort(403, 'Unauthorized - You do not have permission to access this page.');
        }
    }

    /**
     * The SAME proof of ownership, with the one narrow exception the onboarding
     * funnel requires: a signup that has no shop yet.
     *
     * Checkout deliberately PRECEDES shop creation — OnboardingResumeService runs
     * STEP_SELECT_PLAN → STEP_PAYMENT → STEP_CREATE_SHOP, and
     * findPendingSubscription() looks for a term with shop_id IS NULL — so plan
     * selection and payment must stay reachable before any tenant exists. A
     * shop-less user has no role to prove anything with and, crucially, no tenant
     * data to leak: there is no shop, no subscription and no invoice to read.
     *
     * Deliberately NOT folded back into abortUnlessOwner(): the exception is for
     * the pre-tenant funnel only. startTrial() also uses this lenient variant —
     * plan selection (including trial) deliberately precedes shop creation, so
     * a shop-less caller must reach it. Once a shop exists, this collapses back
     * to the full abortUnlessOwner() check, so a staff/role-less member of an
     * existing shop is still refused exactly as before.
     */
    private function abortUnlessOwnerOrOnboarding(): void
    {
        if (Auth::user()?->shop_id === null) {
            return;
        }

        $this->abortUnlessOwner();
    }

    /**
     * The single TERMINAL response for "this shop may not buy right now".
     *
     * blocksNewPaidTerm() returns true for two very different reasons, and every
     * caller used to collapse both into one redirect to subscription.status with
     * "Your shop already has an active paid subscription."
     *
     * That was wrong twice over. It is a LIE during an administrative hold (the
     * shop may have no subscription at all), and it is an infinite REDIRECT LOOP:
     * status() forwards to plans whenever there is no subscription to render, and
     * plans forwarded straight back here.
     *
     * So an administrative hold terminates on its own axis instead:
     *   • read_only  → the dashboard, which an admin deliberately left browsable.
     *                  It renders flash messages and carries logout in the nav,
     *                  and exposes no purchase control.
     *   • suspended  → the established contact-support deny.
     * Neither path enables a purchase, and neither exposes billing history.
     *
     * A live paid term keeps the original honest message.
     */
    private function purchaseBlockedResponse()
    {
        $shop = Auth::user()?->shop;

        if ($shop && $shop->suspensionIsAdministrative()) {
            $message = 'Your shop is under an administrative hold by JewelFlows. '
                . 'A subscription purchase cannot lift it — please contact support.';

            if (($shop->access_mode ?? '') === 'read_only') {
                return redirect()->route('dashboard')->with('error', $message);
            }

            return SubscriptionRecovery::denyAdministrative(request(), $message);
        }

        return redirect()->route('subscription.status')
            ->with('error', 'Your shop already has an active paid subscription.');
    }

    public function choosePlan(Request $request)
    {
        $this->abortUnlessOwnerOrOnboarding();

        if ($this->blocksNewPaidTerm()) {
            return $this->purchaseBlockedResponse();
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
        $this->abortUnlessOwnerOrOnboarding();

        $user = Auth::user();

        // Already has a live subscription? Don't start a trial on top of it.
        // Same single gate as every purchase entry point, plus `trial` itself —
        // a trial may be upgraded to a paid term early, but never restarted.
        // A LAPSED row (expired / cancelled / legacy read_only) is deliberately
        // not a blocker here: the once-per-product rule is enforced inside
        // SubscriptionPaymentService::startTrial(), which throws LogicException
        // and is caught below.
        if ($this->blocksNewPaidTerm()) {
            return $this->purchaseBlockedResponse();
        }

        if ($this->currentSubscription()?->status === 'trial') {
            return redirect()->route('subscription.status')
                ->with('error', 'Your shop already has an active subscription.');
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
        $this->abortUnlessOwnerOrOnboarding();

        // Trial shops may proceed to pay (early upgrade); only live paid shops are blocked.
        if ($this->blocksNewPaidTerm()) {
            return $this->purchaseBlockedResponse();
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
        $this->abortUnlessOwnerOrOnboarding();

        // An administratively suspended shop can NEVER buy its way out — a plan
        // purchase must not lift an admin suspension. Block checkout here because
        // the payment routes are deliberately bypass-listed by both middlewares
        // (so a locked shop can reach the RECOVERY flow), which means this
        // controller is the real server-side boundary for the admin case.
        $currentShop = Auth::user()->shop;
        if ($currentShop && $currentShop->suspensionIsAdministrative()) {
            return response()->json([
                'error' => 'Your shop is suspended by platform admin. Please contact support.',
                'redirect' => route('subscription.status'),
            ], 403);
        }

        // Defence in depth: this endpoint previously had no subscription gate and
        // relied on the upstream pages. Block a live PAID shop from creating a
        // Razorpay order here directly (a trial shop is allowed — early upgrade).
        if ($this->blocksNewPaidTerm()) {
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
        // The owner-return leg of checkout. Staff never open checkout, so they
        // can never legitimately arrive here. The UNAUTHENTICATED, signature-
        // verified webhook() is the machine leg and is deliberately untouched —
        // it is how a captured payment still lands if the browser never returns.
        $this->abortUnlessOwnerOrOnboarding();

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

        // Step 4b: bind the (server-issued) order to the initiating user.
        //
        // Steps 1-4 prove the PAYMENT is genuine; none of them proves the PAYER is
        // whoever holds the session now. createSubscription() below binds
        // $actor = Auth::user(), so without this an unprocessed callback triple
        // belonging to B could be replayed by A and mint A's paid term — after
        // which B's own callback hits idempotency and B can never claim what they
        // paid for. Same L1 guard as ShopServicesController::addCallback(); the
        // machine leg (finalizeCapturedPayment) has always resolved its actor
        // from notes.user_id rather than from a session.
        //
        // It runs BEFORE the idempotency check on purpose: the duplicate branch
        // seeds pending_subscription_id into the caller's session, which would
        // hand A a subscription that is B's.
        //
        // ponytail: enforced only when the note is present, matching the sibling.
        // Every order this codebase mints carries it (createRazorpayOrder), and
        // refusing a noteless legacy order would discard a real customer's
        // captured money rather than protect anyone.
        $orderUserId = $rzpOrder->notes['user_id'] ?? null;
        if ($orderUserId !== null && (int) $orderUserId !== (int) Auth::id()) {
            Log::warning('Subscription callback: order user mismatch', [
                'payment_id' => $paymentId,
                'order_user_id' => $orderUserId,
                'auth_user_id' => Auth::id(),
            ]);

            return redirect()->route('subscription.payment')
                ->with('error', 'This payment does not match your account. Contact support with ref: ' . $paymentId);
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

        // Signature verification MUST run against the raw body first — before we
        // trust any header value including x-razorpay-event-id.
        try {
            $this->webhookService->verifySignature($body, $signature);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'rejected', 'reason' => 'webhook secret not configured'], 500);
        } catch (\Exception $e) {
            Log::warning('Razorpay webhook invalid signature');
            return response()->json(['status' => 'rejected'], 400);
        }

        // At-least-once delivery contract: Razorpay stamps every webhook with a
        // unique x-razorpay-event-id. It is the ONLY primitive that lets us
        // durably collapse a redelivered event to a single record/alert/mutation
        // without adding a schema column. Missing → fail-closed 400 (no mutation,
        // no evidence, no retry storm) because we cannot dedup without it.
        $eventId = trim((string) $request->header('X-Razorpay-Event-Id', ''));
        if ($eventId === '') {
            Log::warning('Razorpay webhook rejected — missing x-razorpay-event-id (cannot dedup at-least-once delivery)');
            return response()->json(['status' => 'rejected', 'reason' => 'missing event id'], 400);
        }

        $payload = json_decode($body, true);
        $event = $payload['event'] ?? '';

        Log::info('Razorpay webhook', ['event' => $event, 'event_id' => $eventId]);

        // Graded outcome reported by each handler:
        //   applied   → 200 (processed OK, or already processed / harmless no-op)
        //   permanent → 200 (durable evidence recorded; Razorpay must NOT retry
        //               — retry cannot fix a permanently-classified event that
        //               the app has already consumed and audited)
        //   transient → 500 (DB/provider hiccup; safe for Razorpay to retry the
        //               STILL-unapplied event)
        $outcome = match ($event) {
            'payment.captured' => $this->webhookService->handlePaymentCaptured($payload, $eventId),
            'refund.created' => $this->webhookService->handleRefundCreated($payload, $eventId),
            'payment.failed' => $this->void_(fn () => $this->webhookService->handlePaymentFailed($payload, $eventId)),
            default => $this->void_(fn () => Log::info('Webhook: unhandled event', ['event' => $event, 'event_id' => $eventId])),
        };

        // Both APPLIED and PERMANENT map to 2xx: PERMANENT means immutable
        // admin-visible evidence has already been durably recorded, so a
        // Razorpay retry would only re-hit the same durable dedup and produce
        // no additional record. Returning 4xx here would trigger an unbounded
        // retry loop with no new information.
        return match ($outcome) {
            SubscriptionPaymentService::OUTCOME_TRANSIENT =>
                response()->json(['status' => 'retry'], 500),
            SubscriptionPaymentService::OUTCOME_PERMANENT =>
                response()->json(['status' => 'not_applied'], 200),
            default => response()->json(['status' => 'ok'], 200),
        };
    }

    /** Run a void handler and report the applied (200) outcome. */
    private function void_(callable $fn): string
    {
        $fn();

        return SubscriptionPaymentService::OUTCOME_APPLIED;
    }

    public function status()
    {
        // The status page renders the shop's full PlatformInvoice history, so it
        // is owner-only for the same reason /billing is.
        $this->abortUnlessOwnerOrOnboarding();

        $shop = Auth::user()?->shop;
        $subscription = $shop
            ? ShopSubscription::where('shop_id', $shop->id)->with('plan')->latest('id')->first()
            : null;

        // Nothing to show yet (no shop, or a first-ever purchase that never
        // completed). The plan picker is both the correct destination and a page
        // that renders flash messages, so nothing is lost on the way.
        //
        // EXCEPT under an administrative hold, where forwarding there is a LOOP:
        // plans refuses a held shop (money cannot lift a hold) and used to forward
        // straight back here. purchaseBlockedResponse() is the terminal answer for
        // exactly this state, so serve it directly instead of bouncing.
        if (! $shop || ! $subscription || ! $subscription->plan) {
            if ($shop && $shop->suspensionIsAdministrative()) {
                return $this->purchaseBlockedResponse();
            }

            return redirect()->route('subscription.plans');
        }

        // A shop that cannot reach the ERP is served HERE rather than forwarded.
        // The "Plan & Billing" tab lives inside the ERP middleware group, so
        // forwarding a locked shop there bounces it straight back out to the plan
        // picker — and the flash message dies on that second hop. Every failure
        // path in paymentCallback() redirects to this route, so that bounce is
        // precisely why refund references and signature errors were invisible to
        // the one person who needed to quote them to support.
        if (($shop->access_mode ?? 'active') !== 'active') {
            $daysRemaining = $subscription->daysRemaining();
            $isExpired     = $daysRemaining !== null && $daysRemaining < 0;
            $isInGrace     = $isExpired
                && $subscription->grace_ends_at
                && Carbon::now()->lte($subscription->grace_ends_at);

            // Same data contract as SettingsController::edit()'s subscription tab,
            // so the shared status view renders identically on both routes.
            return view('subscription.status', [
                'subscription'  => $subscription,
                'plan'          => $subscription->plan,
                'daysRemaining' => $daysRemaining,
                'isInGrace'     => $isInGrace,
                'isExpired'     => $isExpired,
                'featureLabels' => self::featureLabels(),
                'invoices'      => PlatformInvoice::where('shop_id', $shop->id)
                    ->with('plan')
                    ->latest('issued_at')
                    ->paginate(10)
                    ->withQueryString(),
            ]);
        }

        // Entitled shop: the status display lives inside the Settings tab system,
        // so the old /subscription URL (and any bookmark) forwards there.
        return redirect()->route('settings.edit', ['tab' => 'subscription']);
    }
}

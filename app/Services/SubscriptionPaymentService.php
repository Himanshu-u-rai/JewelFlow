<?php

namespace App\Services;

use App\Jobs\SendPlatformInvoiceEmail;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Services\PlatformInvoiceService;
use App\Support\ShopEdition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

class SubscriptionPaymentService
{
    private function razorpay(): Api
    {
        return new Api(
            config('services.razorpay.key_id'),
            config('services.razorpay.key_secret')
        );
    }

    /**
     * Verify the Razorpay payment signature.
     *
     * @throws SignatureVerificationError
     */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): void
    {
        $this->razorpay()->utility->verifyPaymentSignature([
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ]);
    }

    /**
     * Fetch the Razorpay order and extract plan data from notes.
     *
     * @return array{order: object, plan: Plan, billing_cycle: string}
     * @throws \Exception
     */
    public function fetchAndValidateOrder(string $orderId): array
    {
        $rzpOrder = $this->razorpay()->order->fetch($orderId);

        $planId = $rzpOrder->notes['plan_id'] ?? null;
        $billingCycle = $rzpOrder->notes['billing_cycle'] ?? null;
        $plan = $planId ? Plan::find($planId) : null;

        if (!$plan || !$billingCycle) {
            Log::error('Razorpay order missing plan data in notes', [
                'order_id' => $orderId,
                'notes' => $rzpOrder->notes ?? [],
            ]);
            throw new \Exception('Plan not found in order notes.');
        }

        return [
            'order' => $rzpOrder,
            'plan' => $plan,
            'billing_cycle' => $billingCycle,
        ];
    }

    /**
     * Verify the payment amount matches the plan price.
     */
    public function verifyAmount(object $rzpOrder, Plan $plan, string $billingCycle): int
    {
        $expectedPrice = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $expectedPaise = (int) round($expectedPrice * 100);

        if ((int) $rzpOrder->amount !== $expectedPaise) {
            Log::error('Razorpay amount mismatch', [
                'order_id' => $rzpOrder->id,
                'expected_paise' => $expectedPaise,
                'actual_paise' => $rzpOrder->amount,
                'plan_id' => $plan->id,
            ]);
            throw new \Exception('Payment amount mismatch.');
        }

        return $expectedPaise;
    }

    /**
     * Verify the payment is actually captured on Razorpay.
     *
     * @throws \Exception
     */
    public function verifyPaymentCaptured(string $paymentId): void
    {
        $rzpPayment = $this->razorpay()->payment->fetch($paymentId);

        if ($rzpPayment->status !== 'captured') {
            Log::error('Razorpay payment not captured', [
                'payment_id' => $paymentId,
                'status' => $rzpPayment->status,
            ]);
            throw new \Exception('Payment not captured.');
        }
    }

    /**
     * Check if a subscription already exists for this payment (idempotency).
     */
    public function findExistingSubscription(string $paymentId): ?ShopSubscription
    {
        return ShopSubscription::where('razorpay_payment_id', $paymentId)->first();
    }

    /**
     * Create the subscription and event in a single transaction.
     */
    /**
     * Where a new paid term should begin, given the shop's current subscription.
     *
     *  - No shop yet / no current sub / fully lapsed  → now()  (fresh term today)
     *  - Current TRIAL                                 → trial.ends_at (keep free days)
     *  - Current live PAID (active/grace/read_only)    → throws (no stacked term)
     *
     * Trial end dates are stored at start-of-day; max(ends_at, today) guards the
     * (rare) case of paying on the trial's final day so the paid term never
     * backdates before now.
     */
    private function paidTermStartsAt(): Carbon
    {
        $now    = Carbon::now();
        $shopId = Auth::user()?->shop_id;

        if (! $shopId) {
            return $now; // pay-before-shop onboarding: first-ever purchase
        }

        $current = ShopSubscription::where('shop_id', $shopId)->latest('id')->first();

        if (! $current) {
            return $now;
        }

        if (in_array($current->status, ['active', 'grace', 'read_only'], true)) {
            throw new \LogicException('Shop already has an active paid subscription; cannot start a second paid term.');
        }

        if ($current->status === 'trial' && $current->ends_at) {
            $trialEnd = Carbon::parse($current->ends_at)->startOfDay();
            return $trialEnd->greaterThan($now) ? $trialEnd : $now;
        }

        // expired / cancelled trial, or trial with no end date → fresh term today
        return $now;
    }

    public function createSubscription(
        Plan $plan,
        string $billingCycle,
        float $expectedPrice,
        string $paymentId,
        string $orderId,
    ): ShopSubscription {
        $admin = $this->systemAdmin();

        if (!$admin) {
            Log::error('Subscription payment callback failed: no platform super admin found.', [
                'payment_id' => $paymentId,
                'user_id' => Auth::id(),
            ]);
            throw new \Exception('Platform configuration incomplete.');
        }

        // A paid purchase is ALWAYS active. The term is a pure function of the
        // billing cycle — trial_days is NEVER read here, otherwise a paid yearly
        // purchase would silently collapse into a 7-day trial window (the bug this
        // method previously had).
        $status = 'active';

        // Term anchoring (early-upgrade aware):
        //  - First-ever purchase (or fully lapsed): the term starts NOW.
        //  - Shop currently on TRIAL: the paid term starts when the trial ENDS, so
        //    the customer keeps every free trial day they have left and there is no
        //    read-only gap at the seam.
        //  - Shop already holds a LIVE PAID subscription (active/grace/read_only):
        //    refuse — never stack a second paid term. This is the authoritative
        //    money guard; the controller gates block reaching here, this is the
        //    last line of defence.
        $startsAt = $this->paidTermStartsAt();

        $endsAt = $billingCycle === 'yearly'
            ? $startsAt->copy()->addYear()
            : $startsAt->copy()->addMonth();

        $graceEndsAt = $endsAt->copy()->addDays($plan->grace_days ?? config('business.subscription_grace_days'));

        try {
            $invoiceId = null;

            $subscription = DB::transaction(function () use (
                $plan, $status, $startsAt, $endsAt, $graceEndsAt,
                $billingCycle, $expectedPrice, $paymentId, $orderId, $admin,
                &$invoiceId
            ) {
                $subscription = ShopSubscription::create([
                    'shop_id' => Auth::user()->shop_id,
                    'user_id' => Auth::id(),
                    'plan_id' => $plan->id,
                    'status' => $status,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'grace_ends_at' => $graceEndsAt,
                    'billing_cycle' => $billingCycle,
                    'price_paid' => $expectedPrice,
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_order_id' => $orderId,
                    'updated_by_admin_id' => $admin->id,
                    'actor_type' => 'self_service',
                ]);

                SubscriptionEvent::create([
                    'shop_subscription_id' => $subscription->id,
                    'shop_id' => $subscription->shop_id,
                    'admin_id' => $admin->id,
                    'event_type' => 'subscription.paid',
                    'before' => null,
                    'after' => $subscription->toArray(),
                    'reason' => 'Payment via Razorpay: ' . $paymentId,
                ]);

                // Generate platform invoice for this payment — but only when the
                // shop already exists. In the pay-before-shop onboarding flow the
                // subscription is created with a null shop_id, and platform_invoices
                // requires a shop_id (NOT NULL). The invoice is therefore deferred
                // until the shop is created in ShopController::store().
                if ($subscription->shop_id) {
                    $invoice   = app(PlatformInvoiceService::class)->issueForSubscription($subscription);
                    $invoiceId = $invoice->id;
                }

                if (Auth::user()->shop_id && Auth::user()->shop) {
                    Auth::user()->shop->forceFill([
                        'access_mode' => 'active',
                        'is_active' => true,
                    ])->save();
                }

                // Grant the edition this product's plan unlocks. Only possible
                // once a shop exists — in the pay-before-shop onboarding flow
                // the subscription is created with a null shop_id and the grant
                // happens when the shop is later created.
                $this->grantEditionForSubscription($subscription, $plan);

                return $subscription;
            });

            // Email the receipt after the transaction commits (avoids holding DB lock during mail)
            if ($invoiceId) {
                dispatch(new SendPlatformInvoiceEmail($invoiceId));
            }

            return $subscription;
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            Log::info('Concurrent payment callback caught by unique constraint', [
                'payment_id' => $paymentId,
            ]);
            return ShopSubscription::where('razorpay_payment_id', $paymentId)->firstOrFail();
        }
    }

    /**
     * Renew a subscription by creating a NEW row for the next term.
     *
     * The current row is never mutated — each paid term lives in its own
     * shop_subscriptions row so billing history stays append-only.
     *
     * Term anchoring:
     *  - Renewing BEFORE expiry or DURING grace: the new term starts at the old
     *    row's ends_at, so the customer keeps every paid day they have left.
     *  - Renewing AFTER the grace window has fully lapsed: the new term starts now.
     */
    public function renewSubscription(
        ShopSubscription $current,
        string $billingCycle,
        float $price,
        string $paymentId,
        string $orderId,
    ): ShopSubscription {
        // Idempotency: if this payment already produced a row, return it.
        $existing = ShopSubscription::where('razorpay_payment_id', $paymentId)->first();
        if ($existing) {
            return $existing;
        }

        $now = Carbon::now();

        // grace_ends_at is the true end of the customer's paid+grace entitlement.
        // Fall back to ends_at when grace was never set.
        $graceEndsAt = $current->grace_ends_at
            ? Carbon::parse($current->grace_ends_at)
            : ($current->ends_at ? Carbon::parse($current->ends_at) : $now);

        $renewingAfterExpiry = $now->gt($graceEndsAt);

        if ($renewingAfterExpiry || !$current->ends_at) {
            // Customer let it fully lapse — fresh term from today.
            $startsAt = $now->copy();
        } else {
            // Still inside the paid term or grace — extend from the original ends_at
            // so no paid days are lost.
            $startsAt = Carbon::parse($current->ends_at);
        }

        $endsAt = $billingCycle === 'yearly'
            ? $startsAt->copy()->addYear()
            : $startsAt->copy()->addMonth();

        $plan = $current->plan;
        $graceDays = $plan?->grace_days ?? config('business.subscription_grace_days');
        $newGraceEndsAt = $endsAt->copy()->addDays($graceDays);

        try {
            return DB::transaction(function () use (
                $current, $startsAt, $endsAt, $newGraceEndsAt,
                $billingCycle, $price, $paymentId, $orderId, $plan
            ) {
                $renewed = ShopSubscription::create([
                    'shop_id' => $current->shop_id,
                    'user_id' => $current->user_id,
                    'plan_id' => $current->plan_id,
                    'status' => 'active',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'grace_ends_at' => $newGraceEndsAt,
                    'billing_cycle' => $billingCycle,
                    'price_paid' => $price,
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_order_id' => $orderId,
                    'updated_by_admin_id' => $current->updated_by_admin_id,
                    'actor_type' => 'self_service',
                ]);

                SubscriptionEvent::create([
                    'shop_subscription_id' => $renewed->id,
                    'shop_id' => $renewed->shop_id,
                    'admin_id' => null,
                    'event_type' => 'subscription.renewed',
                    'before' => $current->toArray(),
                    'after' => $renewed->toArray(),
                    'reason' => 'Renewal via Razorpay: ' . $paymentId
                        . ' (previous subscription #' . $current->id . ')',
                ]);

                // A renewal re-affirms the edition and re-points it at the new
                // backing subscription row (idempotent — keeps the edition live).
                $this->grantEditionForSubscription($renewed, $plan ?? $renewed->plan);

                return $renewed;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            Log::info('Concurrent renewal callback caught by unique constraint', [
                'payment_id' => $paymentId,
            ]);
            return ShopSubscription::where('razorpay_payment_id', $paymentId)->firstOrFail();
        }
    }

    /**
     * Create a Razorpay order for payment initiation.
     */
    public function createRazorpayOrder(Plan $plan, string $billingCycle): object
    {
        $price = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $amountPaise = (int) round($price * 100);
        $user = Auth::user();

        return $this->razorpay()->order->create([
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => 'jf_' . $user->id . '_' . time(),
            'notes' => [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $billingCycle,
                'user_id' => $user->id,
            ],
        ]);
    }

    /**
     * Grant the edition a product subscription unlocks.
     *
     * Skipped (intentionally) when the subscription has no shop yet — that is
     * the pay-before-shop onboarding flow, where the edition is granted once
     * the shop is created. The grant is idempotent and source='subscription'
     * so a later renewal / duplicate webhook never duplicates the row, and a
     * lapse can revoke it only when nothing else backs it.
     *
     * Any failure here is logged but never bubbles up — a payment must not be
     * lost because an edition grant hiccuped. Reconcilers / the next renewal
     * re-affirm the grant.
     */
    /**
     * The "trial family" an edition belongs to. A shop gets one free trial per
     * family, NOT per individual edition:
     *   - retailer + manufacturer share the 'erp' family (the core JewelFlow ERP)
     *   - dhiran is its own family
     * So a shop that trialed retailer cannot also trial manufacturer for free,
     * but it can separately trial Dhiran.
     */
    public function trialFamilyFor(string $edition): string
    {
        return match ($edition) {
            ShopEdition::RETAILER, ShopEdition::MANUFACTURER => 'erp',
            default => $edition,
        };
    }

    /**
     * Whether this shop OR user has already used its free trial for the family
     * the given edition belongs to.
     *
     * Eligibility is keyed on BOTH the shop and the user, not just the shop. The
     * user dimension matters because in the pre-shop onboarding flow a trial is
     * created with shop_id = NULL — so a shop-only check would never see it and
     * a fresh user could mint trials before their shop exists. Keying on the user
     * (which is 1:1 with a unique mobile number) makes the "one trial per family"
     * cap robust by DESIGN, not as an accident of the user↔shop data model.
     *
     * A trial is any subscription this identity has ever held with price_paid = 0
     * AND no razorpay_payment_id. We map each to its family and compare.
     *
     * @param int|null $shopId  the shop (null in pre-shop onboarding)
     * @param int|null $userId  the user (always known when starting a trial)
     */
    public function hasUsedTrialForFamily(?int $shopId, string $edition, ?int $userId = null): bool
    {
        $family = $this->trialFamilyFor($edition);

        $query = ShopSubscription::query()
            ->whereNull('razorpay_payment_id')
            ->where('price_paid', 0)
            ->where(function ($q) use ($shopId, $userId) {
                $matched = false;
                if ($shopId !== null) {
                    $q->orWhere('shop_id', $shopId);
                    $matched = true;
                }
                if ($userId !== null) {
                    $q->orWhere('user_id', $userId);
                    $matched = true;
                }
                // Nothing to scope by → match nothing (fail safe, never match all).
                if (! $matched) {
                    $q->whereRaw('1 = 0');
                }
            })
            ->with('plan.platformProduct');

        foreach ($query->get() as $sub) {
            $grantEdition = $sub->plan?->grantsEdition();
            if ($grantEdition && $this->trialFamilyFor($grantEdition) === $family) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start a free trial of a product for the current shop — no payment, no card.
     *
     * Creates a 'trial'-status subscription that grants the product's edition and
     * is fully writable for config('business.subscription_trial_days') days. There
     * is NO extra grace window on top of the trial (grace_ends_at = ends_at), so
     * when the trial ends the scheduler drops the shop straight to READ-ONLY
     * (data preserved, writes blocked) — the owner sees their data and buys a plan
     * to continue. A shop may trial each family (erp / dhiran) only once.
     *
     * @throws \LogicException if the shop already trialed this family.
     */
    public function startTrial(Plan $plan): ShopSubscription
    {
        $authUser = Auth::user();
        if (! $authUser) {
            throw new \LogicException('You must be signed in to start a trial.');
        }
        $shopId = $authUser->shop_id;
        $userId = $authUser->id;

        $edition = $plan->grantsEdition();
        if (! $edition) {
            throw new \LogicException('This plan is not linked to a product, so a trial cannot be started.');
        }

        // Keyed on user AND shop — so the cap holds even pre-shop (shop_id null).
        if ($this->hasUsedTrialForFamily($shopId, $edition, $userId)) {
            throw new \LogicException('You have already used your free trial for this product.');
        }

        // A shop a platform admin deliberately SUSPENDED must not be able to lift
        // its own suspension by starting a free trial. Only paying (or an admin)
        // restores a suspended shop — never a self-service trial.
        if ($shopId) {
            $shopRow = $authUser->shop ?? Shop::find($shopId);
            if ($shopRow && $shopRow->access_mode === 'suspended') {
                throw new \LogicException('Your shop is suspended. Please contact support.');
            }
        }

        $admin = $this->systemAdmin();
        if (! $admin) {
            Log::error('Trial start failed: no platform super admin found.', ['user_id' => $userId]);
            throw new \Exception('Platform configuration incomplete.');
        }

        // Admin-configurable trial length (Platform Settings) → config → 30.
        $trialDays = \App\Models\Platform\PlatformSetting::trialDays();
        $startsAt  = Carbon::now();
        $endsAt    = $startsAt->copy()->addDays($trialDays);

        try {
            return DB::transaction(function () use ($plan, $shopId, $userId, $admin, $startsAt, $endsAt, $trialDays) {
            $subscription = ShopSubscription::create([
                'shop_id'             => $shopId,
                'user_id'             => $userId,
                'plan_id'             => $plan->id,
                'status'              => 'trial',
                'starts_at'           => $startsAt,
                'ends_at'             => $endsAt,
                // No bonus grace on a trial: trial end → read-only immediately.
                'grace_ends_at'       => $endsAt,
                'billing_cycle'       => null,
                'price_paid'          => 0,
                'razorpay_payment_id' => null,
                'razorpay_order_id'   => null,
                'updated_by_admin_id' => $admin->id,
                'actor_type'          => 'self_service',
            ]);

            SubscriptionEvent::create([
                'shop_subscription_id' => $subscription->id,
                'shop_id'              => $subscription->shop_id,
                'admin_id'             => $admin->id,
                'event_type'           => 'subscription.trial_started',
                'before'               => null,
                'after'                => $subscription->toArray(),
                'reason'               => 'Free ' . $trialDays . '-day trial of ' . ($plan->grantsEdition() ?? 'product'),
            ]);

            // Grant the edition + make the shop writable for the trial. We never
            // flip a SUSPENDED shop active here (the suspended guard above already
            // refuses), so a self-service trial can never lift an admin suspension.
            if ($shopId) {
                $this->grantEditionForSubscription($subscription, $plan);

                $shop = Auth::user()->shop;
                if ($shop && $shop->access_mode !== 'suspended') {
                    $shop->forceFill([
                        'access_mode' => 'active',
                        'is_active'   => true,
                    ])->save();
                }
            }

            return $subscription;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Lost a concurrent trial-start race (the partial unique index caught
            // the duplicate). Return the trial that won, so the caller still gets
            // a usable trial instead of an error.
            Log::info('Concurrent trial-start caught by unique index', ['user_id' => $userId, 'plan_id' => $plan->id]);
            $existing = ShopSubscription::query()
                ->where('user_id', $userId)
                ->where('plan_id', $plan->id)
                ->whereNull('razorpay_payment_id')
                ->where('price_paid', 0)
                ->latest('id')
                ->first();
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }

    public function grantEditionForSubscription(ShopSubscription $subscription, ?Plan $plan = null): void
    {
        if (! $subscription->shop_id) {
            return;
        }

        $plan ??= $subscription->plan;
        $edition = $plan?->grantsEdition();

        if (! $edition) {
            Log::warning('Subscription has no resolvable edition to grant', [
                'subscription_id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'plan_code' => $plan?->code,
            ]);
            return;
        }

        try {
            $shop = $subscription->shop ?? Shop::find($subscription->shop_id);
            if (! $shop) {
                return;
            }

            ShopEdition::grantFromSubscription($shop, $edition, $subscription->id);
        } catch (\Throwable $e) {
            Log::error('Failed to grant edition for subscription', [
                'subscription_id' => $subscription->id,
                'edition' => $edition,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function systemAdmin(): ?PlatformAdmin
    {
        return PlatformAdmin::where('role', 'super_admin')
            ->orderBy('id')
            ->first();
    }
}

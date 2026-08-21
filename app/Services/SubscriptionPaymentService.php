<?php

namespace App\Services;

use App\Jobs\SendPlatformInvoiceEmail;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\User;
use App\Services\PlatformInvoiceService;
use App\Support\ShopEdition;
use App\Support\SubscriptionTerm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

class SubscriptionPaymentService
{
    /** Coarse outcome a webhook maps to an HTTP status. */
    public const OUTCOME_APPLIED   = 'applied';   // 200 — money applied (or idempotently already applied)
    public const OUTCOME_PERMANENT = 'permanent'; // 4xx — validation failed, retry can never fix
    public const OUTCOME_TRANSIENT = 'transient'; // 5xx — provider/DB hiccup, safe for Razorpay to retry

    /** The only currency subscription orders are ever created in. */
    public const EXPECTED_CURRENCY = 'INR';

    private function alerts(): PlatformSubscriptionAlerts
    {
        return app(PlatformSubscriptionAlerts::class);
    }

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

        // Server-authoritative: the billing cycle must be one we actually price.
        // An unknown cycle would silently fall through verifyAmount's monthly
        // branch and could apply a mispriced term — reject it as permanent.
        if (!in_array($billingCycle, ['monthly', 'yearly'], true)) {
            throw new \Exception("Invalid billing cycle in order notes: {$billingCycle}.");
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
     * Verify the order currency is the one we price in. Orders are always
     * created in INR (createRazorpayOrder), so a differing currency means a
     * tampered / foreign order that must never be applied.
     *
     * @throws \Exception
     */
    public function verifyCurrency(object $rzpOrder): void
    {
        $currency = $rzpOrder->currency ?? null;

        if ($currency !== self::EXPECTED_CURRENCY) {
            Log::error('Razorpay currency mismatch', [
                'order_id' => $rzpOrder->id ?? null,
                'expected' => self::EXPECTED_CURRENCY,
                'actual' => $currency,
            ]);
            throw new \Exception('Payment currency mismatch.');
        }
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
     * All three are judged PER PRODUCT. One shop holds many product
     * subscriptions, so a live ERP term must not block buying Dhiran — being
     * product-blind here charged the card and then refused to activate,
     * leaving the owner paid-up with no service and a support ticket. Product
     * identity comes from Plan::grantsEdition(), the same mapping
     * ShopSubscription::entitlesAccessToday() uses. A plan that maps to no
     * edition falls back to the shop-wide check, keeping the money guard strict
     * when the product cannot be identified.
     *
     * Trial end dates are stored at start-of-day; max(ends_at, today) guards the
     * (rare) case of paying on the trial's final day so the paid term never
     * backdates before now.
     */
    private function paidTermStartsAt(?User $actor, Plan $plan): Carbon
    {
        $now    = Carbon::now();
        $shopId = $actor?->shop_id;

        if (! $shopId) {
            return $now; // pay-before-shop onboarding: first-ever purchase
        }

        $edition = $plan->grantsEdition();

        $current = ShopSubscription::where('shop_id', $shopId)
            ->with('plan.platformProduct')
            ->latest('id')
            ->get()
            ->first(fn (ShopSubscription $sub) => $edition === null || $sub->plan?->grantsEdition() === $edition);

        if (! $current) {
            return $now;
        }

        if (in_array($current->status, ['active', 'grace', 'read_only'], true)) {
            throw new \LogicException(
                'Shop already has an active paid subscription for '
                . ($edition ?? 'this shop')
                . '; cannot start a second paid term.'
            );
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
        ?User $actor = null,
    ): ShopSubscription {
        // The browser callback runs in-session (actor = Auth::user()); the
        // webhook / reconcile paths are session-less and pass the actor resolved
        // from the Razorpay order notes. Everything downstream reads $actor, never
        // Auth, so the identical locked create path serves all three callers.
        $actor = $actor ?? Auth::user();

        $admin = $this->systemAdmin();

        if (!$admin) {
            Log::error('Subscription payment callback failed: no platform super admin found.', [
                'payment_id' => $paymentId,
                'user_id' => $actor?->id,
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
        //  - Shop already holds a LIVE PAID subscription FOR THIS PRODUCT
        //    (active/grace/read_only): refuse — never stack a second paid term
        //    of the same product. This is the authoritative money guard; the
        //    controller gates block reaching here, this is the last line of
        //    defence. A different product is a separate purchase and is allowed.
        $startsAt = $this->paidTermStartsAt($actor, $plan);

        $endsAt = SubscriptionTerm::endsAtFor($billingCycle, $startsAt);
        $graceEndsAt = SubscriptionTerm::graceEndsAtFor($endsAt, $plan);

        try {
            $invoiceId = null;

            $subscription = DB::transaction(function () use (
                $plan, $status, $startsAt, $endsAt, $graceEndsAt,
                $billingCycle, $expectedPrice, $paymentId, $orderId, $admin, $actor,
                &$invoiceId
            ) {
                $subscription = ShopSubscription::create([
                    'shop_id' => $actor?->shop_id,
                    'user_id' => $actor?->id,
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

                // Reactivate the shop — but ONLY a subscription-managed lock, and
                // ONLY under a row lock. Locking here serialises against
                // CheckSubscriptionExpiry (which locks the same row), so a
                // concurrent expiry job can never re-suspend this freshly-paid
                // shop. An administrative suspension (suspended_by set) MUST
                // survive the payment: the money is recorded (row created above)
                // but the shop stays Contact-Support and is NOT reactivated.
                if ($actor?->shop_id) {
                    $shop = Shop::whereKey($actor->shop_id)->lockForUpdate()->first();
                    if ($shop && ! $shop->suspensionIsAdministrative()) {
                        $shop->forceFill([
                            'access_mode' => 'active',
                            'is_active' => true,
                            'suspended_at' => null,
                            'suspension_reason' => null,
                            'deactivated_at' => null,
                        ])->save();
                    }
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

            // Exactly ONE success alert per payment, chosen here in the single
            // genuine-create path — the UniqueConstraintViolation catch below
            // returns the existing row WITHOUT re-firing, so a duplicate
            // callback / webhook / reconcile emits no extra alert.
            //
            // markPaymentResolved() returning true means this payment had a prior
            // open payment.unresolved record: it was RECONCILED, so send only the
            // "reconciled" alert. Otherwise it is a fresh apply. This is the single
            // decision point for BOTH the in-session callback and the sessionless
            // webhook/reconcile paths, so a captured payment can never generate two
            // success emails (previously createSubscription fired paymentApplied
            // while applyCapturedPayment separately fired paymentReconciled).
            if ($paymentId) {
                if ($this->markPaymentResolved($paymentId, $subscription)) {
                    $this->alerts()->paymentReconciled($subscription);
                } else {
                    $this->alerts()->paymentApplied($subscription);
                }
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
     * SESSION-LESS finalization of a captured Razorpay payment.
     *
     * The browser callback creates the subscription in-session. This method is
     * the durable twin used by the webhook and the reconcile command when the
     * callback never ran (browser closed, callback network failed, webhook
     * arrived first). Everything is re-resolved server-side from the Razorpay
     * order — the acting user from notes.user_id, the plan/cycle from notes,
     * the amount cross-checked against the plan — so an attacker-supplied
     * payload can never widen or cheapen a subscription.
     *
     * Idempotent: a second call for the same payment returns the existing row
     * (short-circuit + the unique razorpay_payment_id constraint inside
     * createSubscription). Provider fetches happen BEFORE createSubscription's
     * DB::transaction, so no network call is ever made under a row lock.
     *
     * @throws \RuntimeException  order notes carry no resolvable user
     * @throws \LogicException    shop already holds a live paid term (anti-stack)
     * @throws \Exception         amount mismatch / not captured / plan missing
     */
    public function finalizeCapturedPayment(string $orderId, string $paymentId): ShopSubscription
    {
        if ($existing = $this->findExistingSubscription($paymentId)) {
            return $existing;
        }

        $orderData = $this->fetchAndValidateOrder($orderId);
        $rzpOrder = $orderData['order'];
        $plan = $orderData['plan'];
        $billingCycle = $orderData['billing_cycle'];

        $userId = $rzpOrder->notes['user_id'] ?? null;
        $actor = $userId ? User::find($userId) : null;
        if (!$actor) {
            throw new \RuntimeException("Razorpay order {$orderId} has no resolvable user (notes.user_id).");
        }

        // A deactivated plan must never mint a fresh paid term — reject as permanent.
        if (! $plan->is_active) {
            throw new \Exception("Plan {$plan->id} is not active; payment cannot be applied.");
        }

        $this->verifyCurrency($rzpOrder);
        $this->verifyAmount($rzpOrder, $plan, $billingCycle);
        $this->verifyPaymentCaptured($paymentId);

        $expectedPrice = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        return $this->createSubscription($plan, $billingCycle, (float) $expectedPrice, $paymentId, $orderId, $actor);
    }

    /**
     * Apply a captured payment and report a coarse outcome the webhook maps to an
     * HTTP status. On failure it leaves (or advances) an immutable admin-visible
     * mismatch record and classifies the error as PERMANENT (validation — retry
     * can never fix) or TRANSIENT (provider/DB hiccup — safe to retry). Never
     * throws.
     *
     * @return array{outcome: string, subscription: ?ShopSubscription}
     */
    public function applyCapturedPayment(string $orderId, string $paymentId): array
    {
        try {
            // finalize → createSubscription fires exactly one success alert
            // (reconciled vs applied), including flipping any open unresolved
            // record to resolved. No alert is fired here to avoid a second email.
            $subscription = $this->finalizeCapturedPayment($orderId, $paymentId);

            return ['outcome' => self::OUTCOME_APPLIED, 'subscription' => $subscription];
        } catch (\LogicException $e) {
            // Anti-stacking: a real live paid term already exists. Permanent —
            // Razorpay must stop retrying; a human refunds the double charge.
            $this->recordUnresolvedPayment($orderId, $paymentId, 'anti-stacking: ' . $e->getMessage(), false);

            return ['outcome' => self::OUTCOME_PERMANENT, 'subscription' => null];
        } catch (\Throwable $e) {
            $transient = $this->isTransientPaymentError($e);
            $this->recordUnresolvedPayment(
                $orderId,
                $paymentId,
                ($transient ? 'transient: ' : 'permanent: ') . $e->getMessage(),
                $transient
            );

            return [
                'outcome' => $transient ? self::OUTCOME_TRANSIENT : self::OUTCOME_PERMANENT,
                'subscription' => null,
            ];
        }
    }

    /**
     * Best-effort wrapper the reconcile command and older callers use: finalize
     * the payment or record an admin-visible mismatch. Never throws. Returns the
     * subscription on success, null otherwise (outcome is discarded).
     */
    public function reconcileCapturedPayment(string $orderId, string $paymentId): ?ShopSubscription
    {
        return $this->applyCapturedPayment($orderId, $paymentId)['subscription'];
    }

    /**
     * Classify a finalize failure. PERMANENT = a server-authoritative validation
     * failure that a retry can never fix (mismatched amount/currency, missing
     * plan/user, dead plan, bad cycle, anti-stack). Everything else — provider
     * network errors, DB deadlocks, "platform config incomplete" — is TRANSIENT
     * and safe for Razorpay to retry.
     *
     * ponytail: message-substring match, not typed exceptions. The permanent set
     * is small and owned here; upgrade to dedicated exception classes if the
     * throw-sites ever multiply.
     */
    public function isTransientPaymentError(\Throwable $e): bool
    {
        if ($e instanceof \LogicException) {
            return false; // anti-stack — never retryable
        }

        $permanentNeedles = [
            'amount mismatch',
            'currency mismatch',
            'not captured',
            'Plan not found in order notes',
            'Invalid billing cycle',
            'is not active',
            'has no resolvable user',
        ];

        $msg = $e->getMessage();
        foreach ($permanentNeedles as $needle) {
            if (stripos($msg, $needle) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Immutable, admin-visible evidence that money was captured but could not be
     * applied. Deduped per payment: the first failure creates the record and
     * alerts ops; repeat failures only advance attempt_count / last_failed_at so
     * webhook + reconcile retries never spam the ledger or the inbox.
     */
    public function recordUnresolvedPayment(string $orderId, string $paymentId, string $reason, bool $transient = false): void
    {
        $now = now()->toIso8601String();
        $reasonText = 'Captured Razorpay payment not applied — needs admin review: ' . $reason;

        $event = SubscriptionEvent::where('event_type', 'payment.unresolved')
            ->where('after->payment_id', $paymentId)
            ->first();

        if ($event) {
            $after = $event->after ?? [];
            // Already recovered — leave the resolved history intact.
            if (!empty($after['resolved_at'])) {
                return;
            }
            $after['attempt_count'] = (int) ($after['attempt_count'] ?? 1) + 1;
            $after['last_failed_at'] = $now;
            $after['transient'] = $transient;
            $event->update(['after' => $after, 'reason' => $reasonText]);

            return;
        }

        $event = SubscriptionEvent::create([
            'shop_subscription_id' => null,
            'shop_id' => null,
            'admin_id' => null,
            'event_type' => 'payment.unresolved',
            'before' => null,
            'after' => [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'attempt_count' => 1,
                'first_failed_at' => $now,
                'last_failed_at' => $now,
                'transient' => $transient,
                'resolved_at' => null,
            ],
            'reason' => $reasonText,
        ]);

        Log::critical('Captured payment unresolved — admin review required', [
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'reason' => $reason,
            'transient' => $transient,
        ]);

        // Alert ops once, on the first failure. Transient → "retrying"; permanent
        // → "needs manual review / refund".
        if ($transient) {
            $this->alerts()->reconciliationRequired($event);
        } else {
            $this->alerts()->permanentFailure($event);
        }
    }

    /**
     * Flip a prior payment.unresolved record to resolved when its payment finally
     * applies. Returns true only when it actually transitioned an open record
     * (so the reconciled alert fires exactly once).
     */
    private function markPaymentResolved(string $paymentId, ShopSubscription $subscription): bool
    {
        $event = SubscriptionEvent::where('event_type', 'payment.unresolved')
            ->where('after->payment_id', $paymentId)
            ->first();

        if (!$event) {
            return false;
        }

        $after = $event->after ?? [];
        if (!empty($after['resolved_at'])) {
            return false;
        }

        $after['resolved_at'] = now()->toIso8601String();
        $after['resolved_subscription_id'] = $subscription->id;
        $event->update([
            'after' => $after,
            'reason' => $event->reason . ' [RESOLVED — subscription #' . $subscription->id . ']',
        ]);

        return true;
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
        $today = $now->copy()->startOfDay(); // Asia/Kolkata business date

        // grace_ends_at is the true end of the customer's paid+grace entitlement.
        // Fall back to ends_at when grace was never set.
        $graceEndsAt = $current->grace_ends_at
            ? Carbon::parse($current->grace_ends_at)
            : ($current->ends_at ? Carbon::parse($current->ends_at) : $now);

        // Calendar compare (inclusive): grace is valid THROUGH grace_ends_at, so the
        // term has only fully lapsed once today is strictly past it. Comparing an
        // afternoon $now against a midnight grace_ends_at would lapse a day early.
        $renewingAfterExpiry = $today->gt($graceEndsAt->copy()->startOfDay());

        if ($renewingAfterExpiry || !$current->ends_at) {
            // Customer let it fully lapse — fresh term from today.
            $startsAt = $now->copy();
        } else {
            // Still inside the paid term or grace — extend from the day AFTER the
            // original inclusive To so no paid days are lost and terms never overlap.
            $startsAt = Carbon::parse($current->ends_at)->startOfDay()->addDay();
        }

        $endsAt = SubscriptionTerm::endsAtFor($billingCycle, $startsAt);

        $plan = $current->plan;
        $newGraceEndsAt = SubscriptionTerm::graceEndsAtFor($endsAt, $plan);

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

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
        $startsAt = Carbon::now();
        $status = 'active';
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

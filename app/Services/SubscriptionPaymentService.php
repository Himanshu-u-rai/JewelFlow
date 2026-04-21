<?php

namespace App\Services;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
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

        $startsAt = Carbon::now();
        $status = 'active';

        if (($plan->trial_days ?? 0) > 0) {
            $status = 'trial';
            $endsAt = $startsAt->copy()->addDays($plan->trial_days);
        } else {
            $endsAt = $billingCycle === 'yearly'
                ? $startsAt->copy()->addYear()
                : $startsAt->copy()->addMonth();
        }

        $graceEndsAt = $endsAt->copy()->addDays($plan->grace_days ?? config('business.subscription_grace_days'));

        try {
            return DB::transaction(function () use (
                $plan, $status, $startsAt, $endsAt, $graceEndsAt,
                $billingCycle, $expectedPrice, $paymentId, $orderId, $admin
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

                if (Auth::user()->shop_id && Auth::user()->shop) {
                    Auth::user()->shop->forceFill([
                        'access_mode' => 'active',
                        'is_active' => true,
                    ])->save();
                }

                return $subscription;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            Log::info('Concurrent payment callback caught by unique constraint', [
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

    private function systemAdmin(): ?PlatformAdmin
    {
        return PlatformAdmin::where('role', 'super_admin')
            ->orderBy('id')
            ->first();
    }
}

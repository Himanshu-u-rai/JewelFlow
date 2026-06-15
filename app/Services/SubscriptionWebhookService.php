<?php

namespace App\Services;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;

class SubscriptionWebhookService
{
    private function razorpay(): Api
    {
        return new Api(
            config('services.razorpay.key_id'),
            config('services.razorpay.key_secret')
        );
    }

    /**
     * Verify the webhook signature.
     *
     * @throws \Exception
     */
    public function verifySignature(string $body, ?string $signature): void
    {
        $secret = config('services.razorpay.webhook_secret');

        if (!$secret) {
            Log::error('Razorpay webhook secret is not configured. Rejecting webhook request.');
            throw new \RuntimeException('Webhook secret not configured.');
        }

        $this->razorpay()->utility->verifyWebhookSignature($body, $signature ?? '', $secret);
    }

    /**
     * Handle the payment.captured webhook event.
     */
    public function handlePaymentCaptured(array $payload): void
    {
        $paymentId = $payload['payload']['payment']['entity']['id'] ?? null;
        if (!$paymentId) {
            return;
        }

        $sub = ShopSubscription::where('razorpay_payment_id', $paymentId)->first();

        if (!$sub) {
            Log::info('Webhook: payment.captured — no subscription found for payment', [
                'payment_id' => $paymentId,
            ]);
            return;
        }

        $updates = [];

        // Backward-compat: any leftover trial subscription is promoted to active.
        if ($sub->status === 'trial') {
            $updates['status'] = 'active';
        }

        // Defensive belt-and-suspenders: a sub created before the trial-term bug
        // fix (or via a delayed/duplicate webhook) may carry a shrunken trial-length
        // window instead of the full paid term. If ends_at is clearly shorter than
        // the term implied by billing_cycle, recompute ends_at/grace_ends_at to the
        // full paid term. Post-fix subscriptions already have correct dates, so this
        // is a no-op for them.
        if ($sub->starts_at && $sub->ends_at) {
            $startsAt = Carbon::parse($sub->starts_at);
            $endsAt = Carbon::parse($sub->ends_at);
            $cycle = $sub->billing_cycle ?? 'monthly';

            // A genuine full term is ~1 month or ~1 year. A trial window is ~7 days.
            // Threshold: yearly must extend well past a month; monthly past a week.
            $trialWindowEnd = $cycle === 'yearly'
                ? $startsAt->copy()->addDays(31)
                : $startsAt->copy()->addDays(8);

            if ($endsAt->lte($trialWindowEnd)) {
                $correctEndsAt = $cycle === 'yearly'
                    ? $startsAt->copy()->addYear()
                    : $startsAt->copy()->addMonth();

                $graceDays = $sub->plan?->grace_days ?? config('business.subscription_grace_days');
                $correctGraceEndsAt = $correctEndsAt->copy()->addDays($graceDays);

                $updates['ends_at'] = $correctEndsAt;
                $updates['grace_ends_at'] = $correctGraceEndsAt;

                Log::warning('Webhook: payment.captured — recomputed shrunken term to full paid term', [
                    'payment_id' => $paymentId,
                    'subscription_id' => $sub->id,
                    'billing_cycle' => $cycle,
                    'old_ends_at' => $endsAt->toDateString(),
                    'new_ends_at' => $correctEndsAt->toDateString(),
                ]);
            }
        }

        if ($updates) {
            $sub->update($updates);
            Log::info('Webhook: payment.captured — subscription updated', [
                'payment_id' => $paymentId,
                'subscription_id' => $sub->id,
                'changes' => array_keys($updates),
            ]);
        } else {
            Log::info('Webhook: payment.captured — nothing to change (already active with full term)', [
                'payment_id' => $paymentId,
                'subscription_id' => $sub->id,
            ]);
        }
    }

    /**
     * Handle the payment.failed webhook event.
     */
    public function handlePaymentFailed(array $payload): void
    {
        $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $paymentEntity['order_id'] ?? null;
        $errorDesc = $paymentEntity['error_description'] ?? 'Unknown';

        Log::warning('Webhook: payment.failed', [
            'order_id' => $orderId,
            'error' => $errorDesc,
        ]);

        if ($orderId) {
            SubscriptionEvent::create([
                'shop_subscription_id' => null,
                'shop_id' => null,
                'admin_id' => null,
                'event_type' => 'payment.failed',
                'before' => null,
                'after' => ['order_id' => $orderId, 'error' => $errorDesc],
                'reason' => 'Razorpay webhook: payment failed — ' . $errorDesc,
            ]);
        }
    }

    /**
     * Handle the refund.created webhook event.
     */
    public function handleRefundCreated(array $payload): void
    {
        $refundEntity = $payload['payload']['refund']['entity'] ?? [];
        $paymentId = $refundEntity['payment_id'] ?? null;

        if (!$paymentId) {
            return;
        }

        // Idempotent: only act if not already cancelled.
        $subscription = ShopSubscription::where('razorpay_payment_id', $paymentId)
            ->where('status', '!=', 'cancelled')
            ->first();

        if (!$subscription) {
            Log::info('Webhook: refund.created — subscription already cancelled or not found', [
                'payment_id' => $paymentId,
            ]);
            return;
        }

        // Razorpay sends the refunded amount in paise.
        $refundedRupees = ((int) ($refundEntity['amount'] ?? 0)) / 100;
        $pricePaid = (float) ($subscription->price_paid ?? 0);
        $refundId = $refundEntity['id'] ?? '';

        // Full refund: refunded >= price paid (within a tiny rounding epsilon).
        $isFullRefund = $refundedRupees >= ($pricePaid - 0.01);

        if ($isFullRefund) {
            $before = $subscription->toArray();
            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => Carbon::now(),
            ]);

            SubscriptionEvent::create([
                'shop_subscription_id' => $subscription->id,
                'shop_id' => $subscription->shop_id,
                'admin_id' => null,
                'event_type' => 'subscription.refunded',
                'before' => $before,
                'after' => $subscription->fresh()->toArray(),
                'reason' => 'Razorpay full refund: ' . $refundId
                    . ' (₹' . number_format($refundedRupees, 2) . ' of ₹' . number_format($pricePaid, 2) . ')',
            ]);

            Log::info('Webhook: full refund processed, subscription cancelled', [
                'payment_id' => $paymentId,
                'subscription_id' => $subscription->id,
                'refunded' => $refundedRupees,
                'price_paid' => $pricePaid,
            ]);

            return;
        }

        // Partial refund: the subscription stays active — only log it. before and
        // after status are identical because nothing on the subscription changes.
        $snapshot = $subscription->toArray();

        SubscriptionEvent::create([
            'shop_subscription_id' => $subscription->id,
            'shop_id' => $subscription->shop_id,
            'admin_id' => null,
            'event_type' => 'subscription.partial_refund',
            'before' => $snapshot,
            'after' => $snapshot,
            'reason' => 'Razorpay partial refund: ' . $refundId
                . ' (₹' . number_format($refundedRupees, 2) . ' of ₹' . number_format($pricePaid, 2)
                . ' — subscription remains active)',
        ]);

        Log::info('Webhook: partial refund logged, subscription remains active', [
            'payment_id' => $paymentId,
            'subscription_id' => $subscription->id,
            'refunded' => $refundedRupees,
            'price_paid' => $pricePaid,
        ]);
    }
}

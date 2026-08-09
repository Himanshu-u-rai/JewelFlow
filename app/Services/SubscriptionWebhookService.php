<?php

namespace App\Services;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Support\ShopEdition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     *
     * Returns a coarse outcome the controller maps to an HTTP status:
     *   applied   → 200 (money applied, or idempotently already applied/updated)
     *   permanent → 4xx (validation failed / malformed — Razorpay should stop)
     *   transient → 5xx (provider/DB hiccup — Razorpay should retry)
     *
     * A captured payment that remains UNAPPLIED never returns 'applied'.
     */
    public function handlePaymentCaptured(array $payload): string
    {
        $entity = $payload['payload']['payment']['entity'] ?? [];
        $paymentId = $entity['id'] ?? null;
        if (!$paymentId) {
            // Malformed event with no payment id — retrying cannot fix it.
            return SubscriptionPaymentService::OUTCOME_PERMANENT;
        }

        $sub = ShopSubscription::where('razorpay_payment_id', $paymentId)->first();

        if (!$sub) {
            // The browser callback never created the subscription (browser closed,
            // callback network failed, or the webhook simply raced ahead). Self-heal
            // through the shared, idempotent, session-less finalization path — it
            // re-resolves user/plan/amount server-side from the order and records an
            // admin-visible mismatch if the payment genuinely cannot be applied.
            $orderId = $entity['order_id'] ?? null;
            if (!$orderId) {
                app(SubscriptionPaymentService::class)
                    ->recordUnresolvedPayment('', $paymentId, 'payment.captured webhook carried no order_id', false);
                return SubscriptionPaymentService::OUTCOME_PERMANENT;
            }

            $result = app(SubscriptionPaymentService::class)
                ->applyCapturedPayment($orderId, $paymentId);

            Log::info('Webhook: payment.captured — no existing subscription, ran finalization', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'outcome' => $result['outcome'],
                'subscription_id' => $result['subscription']?->id,
            ]);

            return $result['outcome'];
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

        // The payment is already applied to an existing subscription — idempotent success.
        return SubscriptionPaymentService::OUTCOME_APPLIED;
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
     * Handle the refund.created webhook event — for PARTIAL and FULL refunds.
     *
     * DEDUP is DURABLE, not cache-based. Razorpay may re-deliver the same
     * refund.created long after the alert job's ShouldBeUnique lock (10 min)
     * has expired, so the sole permanent guard is an immutable subscription
     * event recording the provider refund id. We:
     *   1. lock the authoritative subscription row (serialize concurrent
     *      duplicate deliveries of the SAME refund), then
     *   2. re-check for an existing event carrying this refund_id, then
     *   3. record ONE event + fire ONE internal alert.
     * Only a FULL refund cancels the subscription and revokes its edition; a
     * PARTIAL refund leaves the subscription active. Exactly one alert either way.
     */
    public function handleRefundCreated(array $payload): void
    {
        $refundEntity = $payload['payload']['refund']['entity'] ?? [];
        $paymentId = $refundEntity['payment_id'] ?? null;

        if (!$paymentId) {
            return;
        }

        $refundId = $refundEntity['id'] ?? '';
        // Razorpay sends the refunded amount in paise.
        $refundedRupees = ((int) ($refundEntity['amount'] ?? 0)) / 100;
        $currency = $refundEntity['currency'] ?? 'INR';

        DB::transaction(function () use ($paymentId, $refundId, $refundedRupees, $currency) {
            // Lock the authoritative row: a second delivery of the same refund
            // blocks here until the first commits, then re-checks dedup below.
            $subscription = ShopSubscription::where('razorpay_payment_id', $paymentId)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                Log::info('Webhook: refund.created — no subscription for payment', [
                    'payment_id' => $paymentId,
                    'refund_id' => $refundId,
                ]);
                return;
            }

            // DURABLE dedup: an immutable event already recording this provider
            // refund id means it was handled — survives ShouldBeUnique expiry so a
            // delayed duplicate never enqueues a second semantic alert.
            // ponytail: with an empty refund id we cannot dedup, so we process —
            //   acceptable, Razorpay always sends a refund id on refund.created.
            if ($refundId !== '') {
                $already = SubscriptionEvent::where('shop_subscription_id', $subscription->id)
                    ->whereIn('event_type', ['subscription.refunded', 'subscription.partial_refund'])
                    ->where('after->refund_id', $refundId)
                    ->exists();

                if ($already) {
                    Log::info('Webhook: refund.created — refund id already processed, skipping', [
                        'payment_id' => $paymentId,
                        'refund_id' => $refundId,
                        'subscription_id' => $subscription->id,
                    ]);
                    return;
                }
            }

            $pricePaid = (float) ($subscription->price_paid ?? 0);
            // Full refund: refunded >= price paid (within a tiny rounding epsilon).
            $isFullRefund = $refundedRupees >= ($pricePaid - 0.01);
            $classification = $isFullRefund ? 'full' : 'partial';

            $before = $subscription->toArray();

            if ($isFullRefund) {
                $subscription->update([
                    'status' => 'cancelled',
                    'cancelled_at' => Carbon::now(),
                ]);
            }

            // The refund id lives in `after` so the durable dedup query above can
            // find it on any later duplicate delivery.
            $after = array_merge($subscription->fresh()->toArray(), [
                'refund_id' => $refundId,
                'payment_id' => $paymentId,
                'refunded_amount' => $refundedRupees,
                'currency' => $currency,
                'refund_classification' => $classification,
            ]);

            SubscriptionEvent::create([
                'shop_subscription_id' => $subscription->id,
                'shop_id' => $subscription->shop_id,
                'admin_id' => null,
                'event_type' => $isFullRefund ? 'subscription.refunded' : 'subscription.partial_refund',
                'before' => $before,
                'after' => $after,
                'reason' => 'Razorpay ' . $classification . ' refund: ' . $refundId
                    . ' (' . $currency . ' ' . number_format($refundedRupees, 2)
                    . ' of ' . $currency . ' ' . number_format($pricePaid, 2) . ')'
                    . ($isFullRefund ? '' : ' — subscription remains active'),
            ]);

            if ($isFullRefund) {
                // A full refund fully lapses this subscription. Revoke the edition
                // it was backing — but only if no other active source (another
                // paid subscription, or an admin_grant / seed) still justifies it.
                $this->revokeEditionForLapsedSubscription(
                    $subscription,
                    'Subscription fully refunded — service removed.'
                );
            }

            Log::info('Webhook: refund.created processed', [
                'payment_id' => $paymentId,
                'refund_id' => $refundId,
                'subscription_id' => $subscription->id,
                'classification' => $classification,
                'refunded' => $refundedRupees,
                'price_paid' => $pricePaid,
            ]);

            // Exactly one internal alert per validated refund (partial OR full).
            // afterCommit + ShouldBeUnique collapse the in-window duplicate; the
            // durable event row above collapses the out-of-window duplicate.
            app(PlatformSubscriptionAlerts::class)
                ->refundProcessed($subscription->fresh(), $refundId, $refundedRupees, $isFullRefund, $currency);
        });
    }

    /**
     * Revoke the edition a now-lapsed subscription was backing — but only when
     * no other active source (another paid subscription, or an admin_grant /
     * seed) still justifies it. Never lets an edition-revoke failure bubble up
     * and break webhook handling.
     */
    private function revokeEditionForLapsedSubscription(ShopSubscription $subscription, string $reason): void
    {
        if (! $subscription->shop_id) {
            return;
        }

        $edition = $subscription->plan?->grantsEdition();
        if (! $edition) {
            return;
        }

        try {
            $shop = $subscription->shop;
            if (! $shop) {
                return;
            }

            ShopEdition::revokeFromLapsedSubscription(
                $shop,
                $edition,
                $subscription->id,
                $reason
            );
        } catch (\Throwable $e) {
            Log::error('Failed to revoke edition for lapsed subscription', [
                'subscription_id' => $subscription->id,
                'edition' => $edition,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

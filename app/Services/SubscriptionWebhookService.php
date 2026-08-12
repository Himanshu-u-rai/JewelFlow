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
     * FAIL-CLOSED VALIDATION runs BEFORE any mutation. A correctly-signed but
     * malformed refund (missing/blank ids, non-positive amount, non-INR
     * currency) MUST NOT revoke entitlement, mark the subscription refunded,
     * or fire an ops alert. It records an immutable, deduped admin-visible
     * `refund.invalid` event and returns PERMANENT so Razorpay stops retrying.
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
     *
     * Returns a graded outcome the controller maps to an HTTP status:
     *   applied   → 200  (processed, or harmless no-op for an unknown payment)
     *   permanent → 422  (validation failed — retry can never fix)
     *   transient → 500  (provider/DB hiccup — safe for Razorpay to retry)
     */
    public function handleRefundCreated(array $payload): string
    {
        $refundEntity = $payload['payload']['refund']['entity'] ?? [];
        $paymentId = (string) ($refundEntity['payment_id'] ?? '');
        $refundId  = (string) ($refundEntity['id'] ?? '');
        // Razorpay sends the refunded amount in paise (int).
        $amountPaise    = (int) ($refundEntity['amount'] ?? 0);
        $refundedRupees = $amountPaise / 100;
        $currency       = (string) ($refundEntity['currency'] ?? '');

        // ── Fail-closed field validation (BEFORE any mutation or alert) ──
        //
        // Order chosen so the most-diagnosing failure is reported first:
        //   1. blank refund id → cannot dedup, cannot audit → refuse.
        //   2. blank payment id → cannot bind to a subscription → refuse.
        //   3. non-positive amount → nonsense money → refuse.
        //   4. non-INR currency → subscriptions are INR-only
        //      (SubscriptionPaymentService::EXPECTED_CURRENCY); anything else
        //      is a foreign/tampered event → refuse.
        $problem = match (true) {
            $refundId === ''  => 'missing refund id',
            $paymentId === '' => 'missing payment id',
            $amountPaise <= 0 => "non-positive refund amount ({$amountPaise} paise)",
            $currency !== SubscriptionPaymentService::EXPECTED_CURRENCY
                => "currency '{$currency}' does not match expected "
                   . SubscriptionPaymentService::EXPECTED_CURRENCY,
            default => null,
        };

        if ($problem !== null) {
            $this->recordInvalidRefund($paymentId, $refundId, $problem, $amountPaise, $currency);
            return SubscriptionPaymentService::OUTCOME_PERMANENT;
        }

        // ── Transactional processing (fail-safe: any throw → transient/500) ──
        try {
            return DB::transaction(function () use ($paymentId, $refundId, $refundedRupees, $currency) {
                // Lock the authoritative row: a second delivery of the same refund
                // blocks here until the first commits, then re-checks dedup below.
                $subscription = ShopSubscription::where('razorpay_payment_id', $paymentId)
                    ->lockForUpdate()
                    ->first();

                if (!$subscription) {
                    // Unknown payment: not our subscription (could be an unrelated
                    // Razorpay payment on the same key). Harmless no-op — do NOT
                    // 422, or Razorpay would stop retrying a payment that might
                    // later show up. Do NOT 500 either — nothing is broken.
                    Log::info('Webhook: refund.created — no subscription for payment', [
                        'payment_id' => $paymentId,
                        'refund_id'  => $refundId,
                    ]);
                    return SubscriptionPaymentService::OUTCOME_APPLIED;
                }

                // DURABLE dedup: an immutable event already recording this provider
                // refund id means it was handled — survives ShouldBeUnique expiry so a
                // delayed duplicate never enqueues a second semantic alert.
                $already = SubscriptionEvent::where('shop_subscription_id', $subscription->id)
                    ->whereIn('event_type', ['subscription.refunded', 'subscription.partial_refund'])
                    ->where('after->refund_id', $refundId)
                    ->exists();

                if ($already) {
                    Log::info('Webhook: refund.created — refund id already processed, skipping', [
                        'payment_id'      => $paymentId,
                        'refund_id'       => $refundId,
                        'subscription_id' => $subscription->id,
                    ]);
                    return SubscriptionPaymentService::OUTCOME_APPLIED;
                }

                $pricePaid = (float) ($subscription->price_paid ?? 0);
                // Full refund: refunded >= price paid (within a tiny rounding epsilon).
                $isFullRefund   = $refundedRupees >= ($pricePaid - 0.01);
                $classification = $isFullRefund ? 'full' : 'partial';

                $before = $subscription->toArray();

                if ($isFullRefund) {
                    $subscription->update([
                        'status'       => 'cancelled',
                        'cancelled_at' => Carbon::now(),
                    ]);
                }

                // The refund id lives in `after` so the durable dedup query above can
                // find it on any later duplicate delivery.
                $after = array_merge($subscription->fresh()->toArray(), [
                    'refund_id'             => $refundId,
                    'payment_id'            => $paymentId,
                    'refunded_amount'       => $refundedRupees,
                    'currency'              => $currency,
                    'refund_classification' => $classification,
                ]);

                SubscriptionEvent::create([
                    'shop_subscription_id' => $subscription->id,
                    'shop_id'              => $subscription->shop_id,
                    'admin_id'             => null,
                    'event_type'           => $isFullRefund ? 'subscription.refunded' : 'subscription.partial_refund',
                    'before'               => $before,
                    'after'                => $after,
                    'reason'               => 'Razorpay ' . $classification . ' refund: ' . $refundId
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
                    'payment_id'      => $paymentId,
                    'refund_id'       => $refundId,
                    'subscription_id' => $subscription->id,
                    'classification'  => $classification,
                    'refunded'        => $refundedRupees,
                    'price_paid'      => $pricePaid,
                ]);

                // Exactly one internal alert per validated refund (partial OR full).
                // afterCommit + ShouldBeUnique collapse the in-window duplicate; the
                // durable event row above collapses the out-of-window duplicate.
                app(PlatformSubscriptionAlerts::class)
                    ->refundProcessed($subscription->fresh(), $refundId, $refundedRupees, $isFullRefund, $currency);

                return SubscriptionPaymentService::OUTCOME_APPLIED;
            });
        } catch (\Throwable $e) {
            // Any DB/provider hiccup: transaction is rolled back, no partial state.
            // Report transient so Razorpay retries the STILL-unapplied refund.
            Log::error('Webhook: refund.created transient failure — will retry', [
                'payment_id' => $paymentId,
                'refund_id'  => $refundId,
                'error'      => $e->getMessage(),
            ]);
            return SubscriptionPaymentService::OUTCOME_TRANSIENT;
        }
    }

    /**
     * Immutable, admin-visible evidence that a correctly-signed but malformed
     * refund event arrived and was refused BEFORE any subscription mutation.
     * Deduped by refund id (or payment id when refund id is blank): the first
     * failure creates the record; subsequent identical redeliveries only
     * advance attempt_count / last_failed_at so the ledger and inbox stay clean.
     *
     * No alert fired here by design: these are non-actionable refusals surfaced
     * through the same super-admin unresolved-payments panel that shows
     * payment.unresolved records. Log at critical for external alerting hooks.
     */
    private function recordInvalidRefund(string $paymentId, string $refundId, string $reason, int $amountPaise, string $currency): void
    {
        $now        = now()->toIso8601String();
        $reasonText = 'Razorpay refund refused — validation failed: ' . $reason;

        // Dedup key: prefer refund id; fall back to payment id when the event
        // literally arrived without one. If both are blank we still create a
        // row so the audit trail records the malformed delivery.
        $query = SubscriptionEvent::where('event_type', 'refund.invalid');
        if ($refundId !== '') {
            $query->where('after->refund_id', $refundId);
        } elseif ($paymentId !== '') {
            $query->whereNull('after->refund_id')->where('after->payment_id', $paymentId);
        } else {
            $query->whereRaw('1 = 0'); // force miss → always create for pure-empty
        }

        $event = $query->first();

        if ($event) {
            $after = $event->after ?? [];
            $after['attempt_count']  = (int) ($after['attempt_count'] ?? 1) + 1;
            $after['last_failed_at'] = $now;
            $after['transient']      = false;
            $event->update(['after' => $after, 'reason' => $reasonText]);
            return;
        }

        SubscriptionEvent::create([
            'shop_subscription_id' => null,
            'shop_id'              => null,
            'admin_id'             => null,
            'event_type'           => 'refund.invalid',
            'before'               => null,
            'after'                => [
                'payment_id'         => $paymentId,
                'refund_id'          => $refundId,
                'refund_amount_paise'=> $amountPaise,
                'currency'           => $currency,
                'attempt_count'      => 1,
                'first_failed_at'    => $now,
                'last_failed_at'     => $now,
                'transient'          => false,
                'resolved_at'        => null,
            ],
            'reason'               => $reasonText,
        ]);

        Log::critical('Refund refused — validation failed', [
            'payment_id'  => $paymentId,
            'refund_id'   => $refundId,
            'amount_paise'=> $amountPaise,
            'currency'    => $currency,
            'reason'      => $reason,
        ]);
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

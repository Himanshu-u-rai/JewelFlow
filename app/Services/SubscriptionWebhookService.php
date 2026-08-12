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
     *   permanent → 200 (validation failed / malformed — evidence recorded,
     *                    Razorpay must NOT retry the durably-consumed event)
     *   transient → 5xx (provider/DB hiccup — Razorpay should retry)
     *
     * A captured payment that remains UNAPPLIED never returns 'applied'.
     *
     * $eventId — Razorpay's x-razorpay-event-id header. Used for logging /
     * observability and (for durable-audited events) event_id dedup. Existing
     * payment_id-based idempotency (unique constraint + payment.unresolved
     * dedup by payment_id) already collapses redeliveries at the correctness
     * layer, so event_id is threaded here mostly for the audit trail.
     */
    public function handlePaymentCaptured(array $payload, string $eventId = ''): string
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
     *
     * $eventId is persisted in `after.event_id` so a redelivery of the same
     * provider event dedups by event_id and does not create a second row.
     */
    public function handlePaymentFailed(array $payload, string $eventId = ''): void
    {
        $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $paymentEntity['order_id'] ?? null;
        $errorDesc = $paymentEntity['error_description'] ?? 'Unknown';

        Log::warning('Webhook: payment.failed', [
            'order_id' => $orderId,
            'error' => $errorDesc,
            'event_id' => $eventId,
        ]);

        // Durable event-id dedup: a redelivery with the same x-razorpay-event-id
        // must not create an additional row (see: at-least-once delivery contract).
        if ($eventId !== '' && SubscriptionEvent::where('event_type', 'payment.failed')
            ->where('after->event_id', $eventId)->exists()) {
            return;
        }

        if ($orderId) {
            SubscriptionEvent::create([
                'shop_subscription_id' => null,
                'shop_id' => null,
                'admin_id' => null,
                'event_type' => 'payment.failed',
                'before' => null,
                'after' => [
                    'event_id' => $eventId,
                    'order_id' => $orderId,
                    'error' => $errorDesc,
                ],
                'reason' => 'Razorpay webhook: payment failed — ' . $errorDesc,
            ]);
        }
    }

    /**
     * Handle the refund.created webhook event — for PARTIAL and FULL refunds.
     *
     * MONEY-INTEGRITY CONTRACT (authoritative money math):
     *
     *   • ALL amount comparisons happen in INTEGER PAISE. `price_paid` is
     *     converted once via `(int) round($rupees * 100)`. No float compare.
     *
     *   • Currency is checked against the authoritative captured payment's
     *     currency (SubscriptionPaymentService::EXPECTED_CURRENCY / INR-only),
     *     never against an unconnected request value.
     *
     *   • CUMULATIVE refunded is computed live under `lockForUpdate()` by
     *     SUMming `after->refund_amount_paise` across every prior valid refund
     *     event (partial + full) for this subscription. A single refund whose
     *     amount ≤ captured is not enough — cumulative ≤ captured is enforced.
     *
     *   • FULL-vs-PARTIAL classification is based on the AUTHORITATIVE
     *     cumulative-after-this-refund, not on whether this one refund happens
     *     to equal price_paid. The refund that pushes cumulative to captured
     *     is the one that emits full-refund behavior — EXACTLY ONCE.
     *
     *   • Over-refund, currency mismatch, unknown payment, and every field
     *     failure are recorded as immutable `refund.invalid` evidence (safe
     *     fields only — no signature / secret / token / raw payload) and the
     *     handler returns PERMANENT (mapped to 2xx: the event is durably
     *     consumed, Razorpay must not retry it).
     *
     * DEDUP is DURABLE and layered:
     *   1. event_id (x-razorpay-event-id) — collapses at-least-once delivery
     *      across ALL event types (invalid + valid) via `after->event_id`.
     *   2. refund_id — preserved for valid refunds via `after->refund_id`.
     *      Defense-in-depth: even if a provider ever reused the same refund
     *      under a fresh event_id, the cumulative event still records once.
     *
     * CONCURRENCY:
     *   • PostgreSQL advisory transaction-scoped lock keyed on the event_id
     *     serializes duplicate concurrent deliveries of the SAME event before
     *     they can each pass the dedup check.
     *   • ShopSubscription::lockForUpdate serializes concurrent DIFFERENT
     *     refunds against the same subscription so cumulative math is atomic.
     *
     * Returns a graded outcome the controller maps to an HTTP status:
     *   applied   → 200  (processed, or already processed / harmless no-op)
     *   permanent → 200  (immutable evidence recorded; Razorpay must NOT retry)
     *   transient → 500  (provider/DB hiccup — safe for Razorpay to retry)
     */
    public function handleRefundCreated(array $payload, string $eventId = ''): string
    {
        $refundEntity = $payload['payload']['refund']['entity'] ?? [];
        $paymentId = (string) ($refundEntity['payment_id'] ?? '');
        $refundId  = (string) ($refundEntity['id'] ?? '');
        // Razorpay sends the refunded amount in paise (int) — integer arithmetic
        // is authoritative here; the rupee value is derived only for display.
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
            $this->recordInvalidRefund($paymentId, $refundId, $problem, $amountPaise, $currency, $eventId);
            return SubscriptionPaymentService::OUTCOME_PERMANENT;
        }

        // ── Transactional processing (fail-safe: any throw → transient/500) ──
        try {
            return DB::transaction(function () use ($paymentId, $refundId, $refundedRupees, $amountPaise, $currency, $eventId) {
                // Advisory lock keyed on event_id serializes concurrent duplicate
                // deliveries of the SAME event so the dedup check below sees
                // committed state from the first delivery, not a racing sibling.
                // Transaction-scoped: released on commit/rollback automatically.
                if ($eventId !== '' && $this->isPostgres()) {
                    DB::statement("SELECT pg_advisory_xact_lock(hashtextextended(?, 0))", [$eventId]);
                }

                // event_id dedup FIRST — collapses any redelivery of the same
                // provider event regardless of outcome (invalid, partial, full).
                if ($eventId !== '' && SubscriptionEvent::where('after->event_id', $eventId)->exists()) {
                    Log::info('Webhook: refund.created — event_id already processed, skipping', [
                        'event_id'  => $eventId,
                        'refund_id' => $refundId,
                    ]);
                    return SubscriptionPaymentService::OUTCOME_APPLIED;
                }

                // Lock the authoritative row: a second delivery of a DIFFERENT
                // refund for the same subscription blocks until the first
                // commits, so cumulative math stays atomic.
                $subscription = ShopSubscription::where('razorpay_payment_id', $paymentId)
                    ->lockForUpdate()
                    ->first();

                if (!$subscription) {
                    // Unknown payment: we cannot bind to a local subscription. Under
                    // the current contract this MUST be recorded as immutable
                    // `refund.invalid` evidence (not a silent no-op) — a genuine
                    // cross-shop mismatch, tampered payload, or provider misroute
                    // needs admin visibility. Return PERMANENT (→ 2xx after evidence).
                    $this->recordInvalidRefund(
                        $paymentId, $refundId,
                        'no local subscription found for payment id',
                        $amountPaise, $currency, $eventId
                    );
                    return SubscriptionPaymentService::OUTCOME_PERMANENT;
                }

                // Defense-in-depth: refund_id dedup (survives even if a provider
                // ever reuses a refund id under a fresh event_id).
                $alreadyByRefundId = SubscriptionEvent::where('shop_subscription_id', $subscription->id)
                    ->whereIn('event_type', ['subscription.refunded', 'subscription.partial_refund'])
                    ->where('after->refund_id', $refundId)
                    ->exists();

                if ($alreadyByRefundId) {
                    Log::info('Webhook: refund.created — refund id already processed, skipping', [
                        'payment_id'      => $paymentId,
                        'refund_id'       => $refundId,
                        'subscription_id' => $subscription->id,
                    ]);
                    return SubscriptionPaymentService::OUTCOME_APPLIED;
                }

                // Authoritative captured amount from the LOCAL record, in paise.
                $pricePaid     = (float) ($subscription->price_paid ?? 0);
                $capturedPaise = (int) round($pricePaid * 100);

                // Individual over-refund: this one refund alone > captured is nonsense
                // (a tampered or misdirected event). Record evidence, no mutation.
                if ($amountPaise > $capturedPaise) {
                    $this->recordInvalidRefund(
                        $paymentId, $refundId,
                        "refund amount ({$amountPaise} paise) exceeds captured ({$capturedPaise} paise)",
                        $amountPaise, $currency, $eventId
                    );
                    return SubscriptionPaymentService::OUTCOME_PERMANENT;
                }

                // Cumulative over-refund: SUM(prior valid refunds in paise) + this
                // one must not exceed captured. Computed live under lockForUpdate so
                // two concurrent refunds cannot both slip past the guard.
                //
                // Portable SUM over JSON: the JSON cast handles Postgres vs the SQLite
                // in-memory fallback used by some historical tests.
                $priorCumulativePaise = (int) SubscriptionEvent::where('shop_subscription_id', $subscription->id)
                    ->whereIn('event_type', ['subscription.refunded', 'subscription.partial_refund'])
                    ->sum(DB::raw($this->refundAmountPaiseSumExpr()));
                $newCumulativePaise = $priorCumulativePaise + $amountPaise;

                if ($newCumulativePaise > $capturedPaise) {
                    $this->recordInvalidRefund(
                        $paymentId, $refundId,
                        "cumulative refunds ({$newCumulativePaise} paise) would exceed captured ({$capturedPaise} paise)",
                        $amountPaise, $currency, $eventId
                    );
                    return SubscriptionPaymentService::OUTCOME_PERMANENT;
                }

                // Full vs partial is decided by the AUTHORITATIVE cumulative, not
                // by comparing a single refund to price_paid. This is the refund
                // that pushes cumulative to captured → it emits full-refund
                // behavior (cancel + edition revoke) exactly once.
                $isFullRefund   = $newCumulativePaise === $capturedPaise;
                $classification = $isFullRefund ? 'full' : 'partial';

                $before = $subscription->toArray();

                if ($isFullRefund) {
                    $subscription->update([
                        'status'       => 'cancelled',
                        'cancelled_at' => Carbon::now(),
                    ]);
                }

                // The refund id + event id + paise amounts live in `after` so the
                // durable dedup queries above find them on any redelivery, and
                // the next cumulative computation sums correct paise.
                $after = array_merge($subscription->fresh()->toArray(), [
                    'event_id'                    => $eventId,
                    'refund_id'                   => $refundId,
                    'payment_id'                  => $paymentId,
                    'refund_amount_paise'         => $amountPaise,
                    'refunded_amount'             => $refundedRupees,
                    'currency'                    => $currency,
                    'refund_classification'       => $classification,
                    'captured_amount_paise'       => $capturedPaise,
                    'cumulative_refunded_paise'   => $newCumulativePaise,
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
                        . ' of ' . $currency . ' ' . number_format($pricePaid, 2)
                        . '; cumulative ' . $currency . ' ' . number_format($newCumulativePaise / 100, 2) . ')'
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
                    'event_id'                  => $eventId,
                    'payment_id'                => $paymentId,
                    'refund_id'                 => $refundId,
                    'subscription_id'           => $subscription->id,
                    'classification'            => $classification,
                    'refund_amount_paise'       => $amountPaise,
                    'captured_amount_paise'     => $capturedPaise,
                    'cumulative_refunded_paise' => $newCumulativePaise,
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
                'event_id'   => $eventId,
                'payment_id' => $paymentId,
                'refund_id'  => $refundId,
                'error'      => $e->getMessage(),
            ]);
            return SubscriptionPaymentService::OUTCOME_TRANSIENT;
        }
    }

    /** Whether the default DB connection is PostgreSQL (production/staging). */
    private function isPostgres(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'pgsql';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A driver-portable SUM expression over `after->refund_amount_paise`:
     *   • Postgres: `CAST(after->>'refund_amount_paise' AS BIGINT)`
     *   • Other: falls back to a JSON_EXTRACT form that yields 0 outside PG.
     * Non-PG environments are only ever hit by unit-level tests; production
     * and the CI feature suite both run against Postgres.
     */
    private function refundAmountPaiseSumExpr(): string
    {
        return $this->isPostgres()
            ? "COALESCE(CAST(after->>'refund_amount_paise' AS BIGINT), 0)"
            : "COALESCE(JSON_EXTRACT(after, '$.refund_amount_paise'), 0)";
    }

    /**
     * Immutable, admin-visible evidence that a correctly-signed but malformed
     * refund event arrived and was refused BEFORE any subscription mutation.
     *
     * Also used for money-integrity refusals (over-refund, cumulative
     * over-refund, unknown payment) that pass field validation but cannot
     * be safely applied.
     *
     * DEDUP order (strongest → weakest primitive that avoids duplicate rows):
     *   1. event_id  — collapses at-least-once redeliveries of the same event
     *   2. refund_id — collapses same refund arriving under a different event
     *   3. payment_id — last-resort fallback when refund id is missing
     *
     * Safe fields ONLY are persisted (event_id, refund_id, payment_id, paise,
     * currency, attempt counters, reason). No signature / secret / token / raw
     * payload / card details / PII — the admin surface only ever renders these.
     *
     * Alert contract: exactly ONE dedicated `refundPermanentlyRefused` alert
     * fires on the FIRST occurrence (i.e. when a NEW evidence row is created).
     * A redelivery of the same x-razorpay-event-id (or same refund_id) hits the
     * dedup branch above, bumps attempt_count, and does NOT re-alert. This
     * mirrors the captured-payment `permanentFailure` cadence and keeps a
     * malformed retry loop from spamming ops.
     */
    private function recordInvalidRefund(
        string $paymentId,
        string $refundId,
        string $reason,
        int $amountPaise,
        string $currency,
        string $eventId = ''
    ): void {
        $now        = now()->toIso8601String();
        $reasonText = 'Razorpay refund refused — validation failed: ' . $reason;

        // Dedup query: layered from strongest to weakest so a redelivery
        // never creates a duplicate row.
        $query = SubscriptionEvent::where('event_type', 'refund.invalid');
        if ($eventId !== '') {
            $query->where('after->event_id', $eventId);
        } elseif ($refundId !== '') {
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

        $created = SubscriptionEvent::create([
            'shop_subscription_id' => null,
            'shop_id'              => null,
            'admin_id'             => null,
            'event_type'           => 'refund.invalid',
            'before'               => null,
            'after'                => [
                'event_id'           => $eventId,
                'payment_id'         => $paymentId,
                'refund_id'          => $refundId,
                'refund_amount_paise'=> $amountPaise,
                'currency'           => $currency,
                'attempt_count'      => 1,
                'first_failed_at'    => $now,
                'last_failed_at'     => $now,
                'transient'          => false,
                'resolved_at'        => null,
                'validation_reason'  => $reason,
            ],
            'reason'               => $reasonText,
        ]);

        Log::critical('Refund refused — validation failed', [
            'event_id'    => $eventId,
            'payment_id'  => $paymentId,
            'refund_id'   => $refundId,
            'amount_paise'=> $amountPaise,
            'currency'    => $currency,
            'reason'      => $reason,
        ]);

        // Dedicated permanent-validation-failure alert — first occurrence only.
        // Redeliveries hit the dedup update path above and never reach here.
        // The alert body carries only safe references (no signature/secret/
        // token/raw payload). See PlatformSubscriptionAlerts::refundPermanentlyRefused.
        app(PlatformSubscriptionAlerts::class)->refundPermanentlyRefused($created);
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

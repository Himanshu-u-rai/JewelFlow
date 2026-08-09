<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Queued, retryable delivery of a single JewelFlows internal ops alert email.
 *
 * ISOLATION — this job is pinned to its OWN connection + queue:
 *   connection: database   (NOT the global sync default)
 *   queue:      ops-alerts (a dedicated worker drains only this queue)
 * so enabling subscription alerting does not change how any of the 11 unrelated
 * queued workflows (imports, exports, repricing, backups, push, invoices…) run.
 * Those stay on QUEUE_CONNECTION=sync exactly as today.
 *
 * DEDUP — ShouldBeUnique keyed on a stable semantic $eventKey collapses any
 * accidental re-enqueue of the SAME business event (duplicate callback/webhook/
 * reconcile) into a single queued job.
 *
 * Honest delivery guarantees:
 *   • business state  = exactly-once  (unique razorpay_payment_id constraint)
 *   • job dispatch    = deduplicated  (single-fire logic + ShouldBeUnique)
 *   • email delivery  = AT-LEAST-ONCE. SMTP offers no idempotency token, so if
 *     the transport ACCEPTS the mail and the worker then crashes before acking
 *     the queue job, the retry re-sends — a duplicate ops email is possible.
 *     Acceptable: this is an internal alert, never customer-facing money state.
 *
 * The alert body is composed by the caller (PlatformSubscriptionAlerts) and
 * carries NO secrets, signatures, tokens or raw provider payloads — only
 * human-readable references (ids, plan name, amount, dates).
 *
 * Why a job (not a direct Mail::raw): delivery must survive a flaky SMTP hop
 * without rolling back the shop / payment / subscription that already committed.
 * Exceptions bubble so the queue retries; the business transaction is long done.
 */
class SendOpsAlertEmail implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    // Stale-lock TTL for the uniqueness lock: long enough to cover queue latency
    // + retries, short enough that a crashed dispatcher never wedges the key.
    public int $uniqueFor = 600;

    public function __construct(
        public string $subject,
        public string $body,
        public string $eventKey = '',
    ) {
        // Pin to an isolated connection + queue WITHOUT redeclaring the Queueable
        // trait's $connection/$queue properties (a property redeclaration with a
        // differing default fatals under trait composition). This forces the job
        // off the global sync default onto its own database/ops-alerts lane, so a
        // dedicated worker drains ONLY these alerts and the 11 unrelated queued
        // workflows keep running exactly as they do under QUEUE_CONNECTION=sync.
        $this->onConnection('database')->onQueue('ops-alerts');
    }

    /**
     * Stable per-business-event id. Empty key → fall back to content hash so a
     * keyless caller still can't double-enqueue an identical mail.
     */
    public function uniqueId(): string
    {
        return $this->eventKey !== ''
            ? $this->eventKey
            : $this->subject . '|' . md5($this->body);
    }

    public function handle(): void
    {
        // FAIL-CLOSED, dedicated recipient. This pipeline sends ONLY to
        // SUBSCRIPTION_ALERT_EMAIL and deliberately does NOT fall back to
        // PLATFORM_ALERT_EMAIL: that shared key also feeds fraud/shop-health/
        // evaluate-alerts, so a fallback would let one env var silently turn on
        // several unrelated pipelines. If the dedicated inbox is unset, suppress
        // the send and log a clear (non-secret) operational warning instead —
        // SUBSCRIPTION_ALERT_EMAIL is the single staging blocker for this pipeline.
        $to = config('platform.subscription_alert_email');

        if (empty($to)) {
            Log::warning("SendOpsAlertEmail: suppressed — SUBSCRIPTION_ALERT_EMAIL is not configured (no fallback to PLATFORM_ALERT_EMAIL by design). Subject: {$this->subject}");
            return;
        }

        Mail::raw(
            $this->body . "\n\n---\nSent by JewelFlows platform alerting at " . now()->toDateTimeString(),
            function ($message) use ($to) {
                $message->to($to)->subject('[JewelFlows Alert] ' . $this->subject);
            }
        );
    }
}

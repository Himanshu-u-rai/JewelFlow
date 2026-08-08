<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Queued, retryable delivery of a single JewelFlows internal ops alert email.
 *
 * The alert body is composed by the caller (PlatformSubscriptionAlerts) and
 * carries NO secrets, signatures, tokens or raw provider payloads — only
 * human-readable references (ids, plan name, amount, dates).
 *
 * Why a job (not a direct Mail::raw): delivery must survive a flaky SMTP hop
 * without rolling back the shop / payment / subscription that already committed.
 * Exceptions bubble so the queue retries; the business transaction is long done.
 */
class SendOpsAlertEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public string $subject,
        public string $body,
    ) {}

    public function handle(): void
    {
        $to = config('platform.alert_email', env('PLATFORM_ALERT_EMAIL', ''));

        if (empty($to)) {
            Log::warning("SendOpsAlertEmail: suppressed (no PLATFORM_ALERT_EMAIL set). Subject: {$this->subject}");
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

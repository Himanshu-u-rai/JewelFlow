<?php

namespace App\Services;

use App\Models\Platform\PlatformAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PlatformAlertService
{
    private string $alertEmail;

    public function __construct()
    {
        $this->alertEmail = config('platform.alert_email', env('PLATFORM_ALERT_EMAIL', ''));
    }

    /**
     * Evaluate all alert rules. Called every 15 minutes by the scheduler.
     * Never throws — each rule is isolated so one failure doesn't block others.
     */
    public function evaluateAll(): void
    {
        $this->checkFailedAdminLogins();
        $this->checkQueueFailureSpike();
        $this->checkExpiredSubscriptions();
        $this->checkPaymentWebhookFailures();
    }

    /** Rule 1: >10 failed admin logins in the past hour. */
    private function checkFailedAdminLogins(): void
    {
        try {
            $count = PlatformAuditLog::where('action', 'platform_admin.login_failed')
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($count > 10) {
                $this->dispatch(
                    'Security Alert: High failed admin login rate',
                    "{$count} failed admin login attempts in the last hour. Possible brute-force attack."
                );
            }
        } catch (\Throwable $e) {
            Log::warning('PlatformAlertService::checkFailedAdminLogins failed: ' . $e->getMessage());
        }
    }

    /** Rule 2: >50 failed queue jobs in the past hour. */
    private function checkQueueFailureSpike(): void
    {
        try {
            $count = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->count();

            if ($count > 50) {
                $this->dispatch(
                    'Operations Alert: Queue failure spike',
                    "{$count} failed queue jobs in the last hour. Check /admin/system/jobs for details."
                );
            }
        } catch (\Throwable $e) {
            Log::warning('PlatformAlertService::checkQueueFailureSpike failed: ' . $e->getMessage());
        }
    }

    /** Rule 3: Shops whose subscription expired >24h ago without renewal. */
    private function checkExpiredSubscriptions(): void
    {
        try {
            $count = DB::table('shop_subscriptions')
                ->where('status', 'expired')
                ->where('ends_at', '<=', now()->subDay())
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('shop_subscriptions as newer')
                        ->whereColumn('newer.shop_id', 'shop_subscriptions.shop_id')
                        ->where('newer.status', 'active');
                })
                ->count();

            if ($count > 0) {
                $this->dispatch(
                    "Billing Alert: {$count} shop(s) expired without renewal",
                    "{$count} shop subscription(s) expired more than 24 hours ago with no active replacement. Review at /admin/subscriptions."
                );
            }
        } catch (\Throwable $e) {
            Log::warning('PlatformAlertService::checkExpiredSubscriptions failed: ' . $e->getMessage());
        }
    }

    /** Rule 4: Razorpay webhook failures — check razorpay_webhooks table if present. */
    private function checkPaymentWebhookFailures(): void
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable('razorpay_webhooks')) {
                return;
            }

            $total = DB::table('razorpay_webhooks')
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($total < 5) {
                return; // too few samples to be meaningful
            }

            $failed = DB::table('razorpay_webhooks')
                ->where('created_at', '>=', now()->subHour())
                ->whereRaw('processed IS FALSE')
                ->count();

            $rate = $failed / $total;

            if ($rate >= 0.05) {
                $pct = round($rate * 100);
                $this->dispatch(
                    "Payment Alert: Razorpay webhook failure rate {$pct}%",
                    "{$failed} of {$total} Razorpay webhooks in the last hour failed to process ({$pct}%). Check payment processing immediately."
                );
            }
        } catch (\Throwable $e) {
            Log::warning('PlatformAlertService::checkPaymentWebhookFailures failed: ' . $e->getMessage());
        }
    }

    private function dispatch(string $subject, string $body): void
    {
        if (empty($this->alertEmail)) {
            Log::warning("PlatformAlertService: alert suppressed (no PLATFORM_ALERT_EMAIL set). Subject: {$subject}");
            return;
        }

        try {
            Mail::raw(
                $body . "\n\n---\nSent by JewelFlow PlatformAlertService at " . now()->toDateTimeString(),
                function ($message) use ($subject) {
                    $message->to($this->alertEmail)
                            ->subject('[JewelFlow Alert] ' . $subject);
                }
            );
        } catch (\Throwable $e) {
            Log::error("PlatformAlertService: failed to send alert email: " . $e->getMessage());
        }
    }
}

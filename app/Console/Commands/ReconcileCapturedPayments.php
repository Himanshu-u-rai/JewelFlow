<?php

namespace App\Console\Commands;

use App\Services\SubscriptionPaymentService;
use Illuminate\Console\Command;
use Razorpay\Api\Api;

/**
 * Backstop for captured-but-unapplied Razorpay payments.
 *
 * The browser callback and the payment.captured webhook already converge on the
 * shared idempotent finalization path. This command is the belt-and-suspenders
 * for the rare case where BOTH miss (browser closed AND webhook never delivered,
 * or a payment that only settles pending → captured later).
 *
 *   subscription:reconcile-payments                       # scheduled auto-sweep
 *   subscription:reconcile-payments --payment=pay_X --order=order_Y   # support tool
 */
class ReconcileCapturedPayments extends Command
{
    protected $signature = 'subscription:reconcile-payments
        {--payment= : Reconcile a single Razorpay payment id (requires --order)}
        {--order= : Razorpay order id, used with --payment}
        {--days=2 : Days back to scan captured payments in auto mode}
        {--limit=25 : Max payments to actually reconcile per run (bounds provider calls)}
        {--sleep-ms=200 : Pause between provider-touching reconciles (rate-limit safety)}';

    protected $description = 'Finalize captured Razorpay payments never applied to a subscription (lost callback / missed webhook).';

    public function handle(SubscriptionPaymentService $service): int
    {
        // Manual single-payment mode — support tooling for a known reference.
        if ($paymentId = $this->option('payment')) {
            $orderId = $this->option('order');
            if (!$orderId) {
                $this->error('--order is required with --payment.');

                return self::FAILURE;
            }

            $sub = $service->reconcileCapturedPayment($orderId, $paymentId);
            $this->line($sub
                ? "Applied {$paymentId} -> subscription {$sub->id}"
                : "Could not apply {$paymentId} — recorded for admin review.");

            return self::SUCCESS;
        }

        // Auto-sweep mode — bounded so a 10-minute cadence never floods Razorpay.
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        $applied = 0;
        $reconciled = 0; // provider-touching reconciles attempted this run
        foreach ($this->recentCapturedPayments((int) $this->option('days')) as $candidate) {
            $pid = $candidate['id'] ?? null;
            $oid = $candidate['order_id'] ?? null;
            if (!$pid || !$oid) {
                continue;
            }

            // Cheap DB short-circuit before any provider call — free, unbounded.
            if ($service->findExistingSubscription($pid)) {
                continue;
            }

            // Bound the number of provider-touching reconciles per run. Anything
            // beyond the limit is picked up by the next 10-minute sweep.
            if ($reconciled >= $limit) {
                $this->warn("Reconcile limit ({$limit}) reached — remaining candidates deferred to next run.");
                break;
            }

            // Rate-limit safety: brief pause between provider-touching reconciles.
            if ($reconciled > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
            $reconciled++;

            if ($service->reconcileCapturedPayment($oid, $pid)) {
                $applied++;
            }
        }

        $this->info("Reconcile complete: {$applied} payment(s) applied.");

        return self::SUCCESS;
    }

    /**
     * Recent captured payments from Razorpay as [['id' => .., 'order_id' => ..]].
     * A protected seam so tests inject candidates without hitting the network.
     *
     * ponytail: naive list of recent payments; Razorpay retries webhooks for
     * hours, so this sweep only backstops a fully-lost webhook. Upgrade to a
     * persisted payment-intent log if listing ever gets costly.
     */
    protected function recentCapturedPayments(int $days): array
    {
        $from = now()->subDays(max(1, $days))->timestamp;
        $api = new Api(config('services.razorpay.key_id'), config('services.razorpay.key_secret'));

        $out = [];
        $collection = $api->payment->all(['from' => $from, 'count' => 100]);
        foreach (($collection->items ?? []) as $p) {
            if (($p->status ?? null) !== 'captured') {
                continue;
            }
            $out[] = ['id' => $p->id, 'order_id' => $p->order_id ?? null];
        }

        return $out;
    }
}

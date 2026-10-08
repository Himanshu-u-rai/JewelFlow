<?php

namespace App\Console\Commands;

use App\Services\SubscriptionPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\Error as RazorpayError;
use Razorpay\Api\Errors\GatewayError;
use Razorpay\Api\Errors\ServerError;
use Throwable;

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

    private const DEFERRED_RUNS = 'subscription:reconcile-payments:deferred-runs';

    // ponytail: a count in the cache, six runs = one hour at the ten-minute
    // schedule. A cleared cache restarts the count; alerting proper belongs
    // in ops-alerts if this ever needs to be exact.
    private const DEFERRED_RUNS_BEFORE_FAILURE = 6;

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
        try {
            $applied = $this->reconcileRecent($service);
        } catch (Throwable $e) {
            if (! self::isTransientProviderFailure($e)) {
                throw $e;
            }

            return $this->deferred($e);
        }

        Cache::forget(self::DEFERRED_RUNS);
        $this->info("Reconcile complete: {$applied} payment(s) applied.");

        return self::SUCCESS;
    }

    private function reconcileRecent(SubscriptionPaymentService $service): int
    {
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

            if ($service->findExistingSubscription($pid)) {
                continue;
            }

            if ($reconciled >= $limit) {
                $this->warn("Reconcile limit ({$limit}) reached — remaining candidates deferred to next run.");
                break;
            }

            if ($reconciled > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
            $reconciled++;

            if ($service->reconcileCapturedPayment($oid, $pid)) {
                $applied++;
            }
        }

        return $applied;
    }

    /**
     * The provider did not answer, or asked us to slow down. Nothing is lost:
     * the next run, ten minutes on, scans the same two days again, and each
     * payment is applied at most once. So one such run is a warning, not an
     * error. An hour of them in a row is reported as a failure.
     */
    private function deferred(Throwable $e): int
    {
        Cache::add(self::DEFERRED_RUNS, 0, now()->addDay());
        $runs = (int) Cache::increment(self::DEFERRED_RUNS);

        Log::warning('subscription:reconcile-payments: provider unavailable, deferred to the next run', [
            'error' => get_class($e),
            'consecutive_runs' => $runs,
        ]);
        $this->warn('Payment provider unavailable (' . class_basename($e) . "): deferred to the next run. Consecutive: {$runs}.");

        return $runs >= self::DEFERRED_RUNS_BEFORE_FAILURE ? self::FAILURE : self::SUCCESS;
    }

    /** Timeouts and connection failures, provider-side 5xx, and "Too many requests". */
    private static function isTransientProviderFailure(Throwable $e): bool
    {
        return $e instanceof \WpOrg\Requests\Exception
            || $e instanceof ServerError
            || $e instanceof GatewayError
            || ($e instanceof RazorpayError && (int) $e->getHttpStatusCode() === 429);
    }

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

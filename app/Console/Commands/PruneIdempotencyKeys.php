<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Prune RESOLVED idempotency_keys older than the retention window.
 *
 * The mobile app only retries within a few minutes of the original request;
 * 48 hours is comfortably longer than any retry window while keeping the
 * table bounded.
 *
 * ─── S3-09b: why "resolved" is load-bearing ───────────────────────────────
 *
 * This command used to delete by age alone:
 *
 *     IdempotencyKey::where('created_at', '<', $cutoff)->delete();
 *
 * EnsureIdempotency stakes a claim BEFORE running the controller and marks it
 * `response_status = 0` while in flight. If the process dies after the
 * business write commits but before the outcome is recorded, that 0 is all
 * that stands between the client's retry and a second cash movement — the
 * middleware refuses the retry precisely because the row is there.
 *
 * Deleting such a row by age re-permits the operation. Measured, on synthetic
 * data, in tests/Feature/Security/MobileIdempotencyRetentionTest: prune an
 * unresolved claim and the same key books the cash entry a second time.
 *
 * So unresolved claims are RETAINED and reported. This is deliberately not a
 * timer: nothing here decides that an old uncertain operation has become safe
 * to re-run, because nothing here can know that. Only an operator who has
 * reconciled the underlying record can know it, and no command should make
 * that call on their behalf.
 *
 * THIS COMMAND IS NOT A FINANCIAL-SAFETY MECHANISM. It bounds table size. The
 * retention window is not a guarantee about money, and two numbers that are
 * easy to conflate are not the same:
 *
 *   * `--hours` is the ELIGIBILITY THRESHOLD.
 *   * Schedule::command(...)->daily() (routes/console.php) runs at midnight
 *     Asia/Kolkata, so a row eligible at 00:30 waits until the next midnight.
 *     Real deletion age runs from the threshold to roughly threshold + 24h.
 *   * All of which assumes cron is actually invoking `schedule:run`. That is
 *     NOT VERIFIED here and is not verifiable from the test suite.
 *
 * What happens to a retained unresolved claim: an operator reconciles it,
 * one at a time and with evidence, through `mobile:idempotency-claims`
 * (docs/runbooks/idempotency-unresolved-claims.md). No purge option is
 * offered HERE on purpose: deleting an unresolved claim is exactly the
 * dangerous act, and it needs the evidence and approval that tool records,
 * not a flag on a scheduled job.
 *
 * @see app/Http/Middleware/EnsureIdempotency.php
 */
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'mobile:prune-idempotency-keys {--hours=48 : Delete RESOLVED idempotency keys older than this many hours}';

    protected $description = 'Delete resolved idempotency_keys rows older than the retention window (default 48h). Unresolved claims are retained and reported.';

    /**
     * Sentinel EnsureIdempotency writes while a request is in flight.
     *
     * Mirrors EnsureIdempotency::STATUS_IN_FLIGHT. Duplicated rather than
     * shared because the middleware's copy is private and a two-line constant
     * is not worth a new contract between them; if the column is ever made
     * nullable, both sites must treat NULL as in-flight too.
     */
    private const STATUS_IN_FLIGHT = 0;

    public function handle(): int
    {
        $hours  = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);

        // XR-02. Only a claim that recorded its original 2xx response is
        // pruned. Everything else is retained: an unresolved claim (anything
        // outside 100–599, which EnsureIdempotency refuses as in flight — not
        // only the sentinel 0), and a claim an operator reconciled as committed,
        // whose 409 is the only thing stopping that key from booking the entry
        // again. Its created_at is the original staking time, so an age rule
        // would delete it on the next run.
        $deleted = IdempotencyKey::where('created_at', '<', $cutoff)
            ->whereBetween('response_status', [200, 299])
            ->delete();

        $this->info("Pruned {$deleted} resolved idempotency key(s) older than {$hours} hours.");

        $retained = IdempotencyKey::where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->where('response_status', '<', 100)->orWhere('response_status', '>', 599))
            ->count();

        $reconciled = IdempotencyKey::where('created_at', '<', $cutoff)
            ->whereBetween('response_status', [100, 599])
            ->where(fn ($q) => $q->where('response_status', '<', 200)->orWhere('response_status', '>', 299))
            ->count();

        if ($reconciled > 0) {
            $this->line("Retained {$reconciled} reconciled or non-2xx claim(s); they keep their key refused.");
        }

        if ($retained > 0) {
            // Surfaced as a warning, not an error: the command did its job.
            // Each of these is a mutation whose outcome the server never
            // learned, still holding its key so the client cannot re-run it.
            $this->warn(
                "Retained {$retained} UNRESOLVED claim(s) past the window. Each one is a mutation whose "
                . 'outcome is unknown and whose key is still blocked. List them with '
                . '`mobile:idempotency-claims` and reconcile each against the underlying record '
                . '(docs/runbooks/idempotency-unresolved-claims.md); do not delete them to unblock a client.'
            );
        }

        return self::SUCCESS;
    }
}

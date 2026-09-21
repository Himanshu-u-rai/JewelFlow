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
 * OPEN DECISION — what should eventually happen to a retained unresolved
 * claim? Growth is slow (one row per crashed mutation) but unbounded, and
 * there is currently no supported way to clear one. No purge option is
 * offered here on purpose: deleting an unresolved claim is exactly the
 * dangerous act, and it needs an explicit decision plus a reconciliation
 * procedure, not a flag. See §7c-1 of the audit handoff.
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

        $deleted = IdempotencyKey::where('created_at', '<', $cutoff)
            ->where('response_status', '!=', self::STATUS_IN_FLIGHT)
            ->delete();

        $this->info("Pruned {$deleted} resolved idempotency key(s) older than {$hours} hours.");

        $retained = IdempotencyKey::where('created_at', '<', $cutoff)
            ->where('response_status', self::STATUS_IN_FLIGHT)
            ->count();

        if ($retained > 0) {
            // Surfaced as a warning, not an error: the command did its job.
            // Each of these is a mutation whose outcome the server never
            // learned, still holding its key so the client cannot re-run it.
            $this->warn(
                "Retained {$retained} UNRESOLVED claim(s) past the window. Each one is a mutation whose "
                . 'outcome is unknown and whose key is still blocked. Reconcile the underlying record '
                . 'before deciding what to do with them; do not delete them to unblock a client.'
            );
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Prune idempotency_keys older than the retention window.
 *
 * The mobile app only retries within a few minutes of the original request;
 * 48 hours is comfortably longer than any retry window while keeping the
 * table bounded.
 */
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'mobile:prune-idempotency-keys {--hours=48 : Delete idempotency keys older than this many hours}';

    protected $description = 'Delete idempotency_keys rows older than the retention window (default 48h).';

    public function handle(): int
    {
        $hours  = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);

        $deleted = IdempotencyKey::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} idempotency key(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}

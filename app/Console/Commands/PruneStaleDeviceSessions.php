<?php

namespace App\Console\Commands;

use App\Models\MobileDeviceSession;
use Illuminate\Console\Command;

class PruneStaleDeviceSessions extends Command
{
    protected $signature = 'devices:prune-sessions {--days=90 : Delete ended sessions older than this many days}';
    protected $description = 'Delete ended mobile device session rows older than the retention window';

    public function handle(): int
    {
        $days    = (int) $this->option('days');
        $cutoff  = now()->subDays($days);

        // Only delete rows that are already ended (logged_out_at IS NOT NULL).
        // Active sessions are never pruned here — they must be explicitly revoked.
        $deleted = MobileDeviceSession::whereNotNull('logged_out_at')
            ->where('updated_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} ended device session(s) older than {$days} days.");

        return self::SUCCESS;
    }
}

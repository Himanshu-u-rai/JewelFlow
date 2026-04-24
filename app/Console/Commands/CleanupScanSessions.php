<?php

namespace App\Console\Commands;

use App\Models\ScanSession;
use App\Models\ScanEvent;
use Illuminate\Console\Command;

class CleanupScanSessions extends Command
{
    protected $signature = 'scan:cleanup';
    protected $description = 'Expire stale scan sessions and delete old scan events';

    public function handle(): int
    {
        // Mark all sessions past their expiry as expired
        $expired = ScanSession::where('status', 'active')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        // Delete scan events older than 24 hours
        $deleted = ScanEvent::where('created_at', '<', now()->subDay())->delete();

        // Delete expired sessions older than 7 days
        ScanSession::where('status', 'expired')
            ->where('updated_at', '<', now()->subDays(7))
            ->delete();

        $this->info("Expired {$expired} stale sessions. Deleted {$deleted} old scan events.");

        return self::SUCCESS;
    }
}

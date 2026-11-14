<?php

namespace App\Console\Commands;

use App\Models\PendingUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneExpiredUploads extends Command
{
    protected $signature = 'mobile:prune-uploads
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Delete expired and orphaned pending upload records and their disk files.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // 1. Pending rows whose expiry window has passed with no bytes received.
        $stalePending = PendingUpload::pending()
            ->where('expires_at', '<', now())
            ->get();

        // 2. Ready rows that were never consumed after a 24-hour grace window.
        // Not consumable(): that scope means "not expired", which no row 24 hours past expiry is, so this matched
        // nothing and unused photos were never pruned. An expired upload can no longer be consumed (audit N8).
        $unconsumed = PendingUpload::where('status', 'ready')
            ->whereNull('consumed_at')
            ->where('expires_at', '<', now()->subHours(24))
            ->get();

        // 3. Failed rows older than 7 days.
        $failed = PendingUpload::where('status', 'failed')
            ->where('created_at', '<', now()->subDays(7))
            ->get();

        $all = $stalePending->concat($unconsumed)->concat($failed);

        if ($all->isEmpty()) {
            $this->line('No uploads to prune.');
            return self::SUCCESS;
        }

        $this->line("Found {$all->count()} upload(s) to prune" . ($dry ? ' (dry-run — no changes).' : '.'));

        $deletedFiles = 0;
        $deletedRows  = 0;

        foreach ($all as $upload) {
            $this->line("  [{$upload->status}] {$upload->upload_token} (shop {$upload->shop_id})");

            if ($dry) {
                continue;
            }

            // The row first, and only if it is still as selected (same status, still unused): a row that changed
            // since keeps its files. Then its files.
            $deleted = PendingUpload::whereKey($upload->getKey())
                ->where('status', $upload->status)
                ->whereNull('consumed_at')
                ->delete();
            if ($deleted === 0) {
                continue;
            }
            $deletedRows++;

            foreach ([$upload->original_path, $upload->thumbnail_path] as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                    $deletedFiles++;
                }
            }
        }

        if (! $dry) {
            $this->info("Pruned {$deletedRows} row(s) and {$deletedFiles} file(s).");
            Log::info('mobile:prune-uploads complete.', [
                'rows'  => $deletedRows,
                'files' => $deletedFiles,
            ]);
        }

        return self::SUCCESS;
    }
}

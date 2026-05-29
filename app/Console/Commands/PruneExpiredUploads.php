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
        $unconsumed = PendingUpload::consumable()
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

            // Delete disk files.
            foreach ([$upload->original_path, $upload->thumbnail_path] as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                    $deletedFiles++;
                }
            }

            $upload->delete();
            $deletedRows++;
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

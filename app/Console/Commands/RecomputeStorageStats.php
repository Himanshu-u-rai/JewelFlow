<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\ShopStorageStat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RecomputeStorageStats extends Command
{
    protected $signature = 'storage:recompute-stats';

    protected $description = 'Recompute per-shop storage statistics from the public disk.';

    public function handle(): int
    {
        $shops = Shop::query()->select(['id'])->get();
        $count = 0;

        foreach ($shops as $shop) {
            try {
                $directories = [
                    'kyc'              => "kyc/{$shop->id}",
                    'repairs'          => "repairs/{$shop->id}",
                    'karigar-invoices' => "karigar-invoices/{$shop->id}",
                    'items'            => "items/{$shop->id}",
                ];

                $totalFiles = 0;
                $totalBytes = 0;
                $breakdown  = [];

                foreach ($directories as $label => $dir) {
                    $files     = Storage::disk('public')->files($dir, true);
                    $dirBytes  = 0;

                    foreach ($files as $file) {
                        try {
                            $dirBytes += Storage::disk('public')->size($file);
                        } catch (\Throwable) {
                            // Skip unreadable files
                        }
                    }

                    $fileCount        = count($files);
                    $totalFiles      += $fileCount;
                    $totalBytes      += $dirBytes;
                    $breakdown[$label] = [
                        'files' => $fileCount,
                        'bytes' => $dirBytes,
                    ];
                }

                ShopStorageStat::updateOrCreate(
                    ['shop_id' => $shop->id],
                    [
                        'file_count'  => $totalFiles,
                        'total_bytes' => $totalBytes,
                        'breakdown'   => $breakdown,
                        'computed_at' => now(),
                    ]
                );

                $count++;
            } catch (\Throwable $e) {
                $this->warn("Failed to compute stats for shop #{$shop->id}: {$e->getMessage()}");
            }
        }

        $this->info("Recomputed storage stats for {$count} shops.");

        return self::SUCCESS;
    }
}

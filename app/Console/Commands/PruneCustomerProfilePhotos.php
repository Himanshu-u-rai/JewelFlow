<?php

namespace App\Console\Commands;

use App\Services\CustomerProfilePhotoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneCustomerProfilePhotos extends Command
{
    protected $signature = 'customer-photos:prune {--dry-run : Count eligible files without deleting}';
    protected $description = 'Remove unreferenced customer profile photos older than 24 hours on the private disk';

    public function handle(CustomerProfilePhotoService $photos): int
    {
        $count = 0;
        $disk = Storage::disk('local');
        foreach ($disk->allFiles('customer-profile-photos') as $path) {
            if ($disk->lastModified($path) < now()->subDay()->timestamp && $photos->cleanup($path, (bool) $this->option('dry-run'))) {
                $count++;
            }
        }
        $this->info($count.' unreferenced photo(s) '.($this->option('dry-run') ? 'eligible; nothing deleted.' : 'removed.'));

        return self::SUCCESS;
    }
}

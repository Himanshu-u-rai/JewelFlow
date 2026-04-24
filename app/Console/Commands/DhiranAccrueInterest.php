<?php

namespace App\Console\Commands;

use App\Models\Dhiran\DhiranSettings;
use App\Services\DhiranService;
use Illuminate\Console\Command;

class DhiranAccrueInterest extends Command
{
    protected $signature = 'dhiran:accrue-interest';
    protected $description = 'Accrue daily interest on all active Dhiran loans';

    public function handle(): int
    {
        $enabledSettings = DhiranSettings::where('is_enabled', true)->get();
        $totalProcessed = 0;
        $errors = 0;

        $this->info("Found {$enabledSettings->count()} shop(s) with Dhiran enabled.");

        $service = app(DhiranService::class);

        foreach ($enabledSettings as $settings) {
            try {
                $count = $service->accrueInterestBatch($settings->shop_id);
                $this->info("Shop #{$settings->shop_id}: accrued interest on {$count} loan(s)");
                $totalProcessed += $count;
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Shop #{$settings->shop_id}: interest accrual failed — {$e->getMessage()}");
                \Log::error("dhiran:accrue-interest failed for shop #{$settings->shop_id}", ['exception' => $e]);
            }
        }

        $this->info("Done. Total loans processed: {$totalProcessed} across {$enabledSettings->count()} shop(s). Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

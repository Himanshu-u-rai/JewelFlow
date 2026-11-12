<?php

namespace App\Console\Commands;

use App\Models\Dhiran\DhiranLoan;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class DhiranAccrueInterest extends Command
{
    protected $signature = 'dhiran:accrue-interest';
    protected $description = 'Accrue daily interest on all active Dhiran loans';

    public function handle(): int
    {
        // Interest follows the loan (owner 2026-11-12): every shop with an active loan, whatever its plan or its
        // Dhiran switch says. Cross-shop discovery bypasses the tenant scope (it fails closed in console); each
        // shop's work then runs inside its own TenantContext so the tenant-scoped loan queries resolve.
        $shops = DhiranLoan::shopsWithActiveLoans()->map(fn ($id) => (object) ['shop_id' => $id]);
        $totalProcessed = 0;
        $errors = 0;

        $this->info("Found {$shops->count()} shop(s) with active Dhiran loans.");

        $service = app(DhiranService::class);

        foreach ($shops as $shop) {
            try {
                $count = TenantContext::runFor(
                    $shop->shop_id,
                    fn () => $service->accrueInterestBatch($shop->shop_id)
                );
                $this->info("Shop #{$shop->shop_id}: accrued interest on {$count} loan(s)");
                $totalProcessed += $count;
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Shop #{$shop->shop_id}: interest accrual failed — {$e->getMessage()}");
                \Log::error("dhiran:accrue-interest failed for shop #{$shop->shop_id}", ['exception' => $e]);
            }
        }

        $this->info("Done. Total loans processed: {$totalProcessed} across {$shops->count()} shop(s). Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\FiscalYearSnapshotService;
use App\Support\FinancialYear;
use Illuminate\Console\Command;
use Throwable;

/**
 * Takes the 'automatic' snapshot of the financial year that just ended, once per retail shop. Idempotent: a shop that
 * already has one is skipped, so the daily run is self-healing if the server was down on 1 April.
 * It never touches shop_rules.financial_lock_date; locking is always the owner's explicit act (Art. VII item 9).
 */
class SnapshotYearEnd extends Command
{
    protected $signature = 'books:snapshot-year-end';

    protected $description = 'Take the automatic year-end snapshot of the previous financial year for every retail shop.';

    public function handle(FiscalYearSnapshotService $service): int
    {
        $fy = FinancialYear::current()->previous();
        $failed = 0;
        $taken = 0;

        foreach (Shop::query()->orderBy('id')->get() as $shop) {
            if (! $shop->isRetailer()) {
                continue;
            }
            try {
                $before = $service->latest($shop->id, $fy);
                $snap = $service->take($shop->id, $fy, FiscalYearSnapshotService::KIND_AUTOMATIC);
                if (! $before || (int) $before->id !== (int) $snap->id) {
                    $taken++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("Shop {$shop->id}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Year-end snapshot {$fy->label()}: {$taken} taken, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

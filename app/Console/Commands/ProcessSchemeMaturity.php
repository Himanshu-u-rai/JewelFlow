<?php

namespace App\Console\Commands;

use App\Services\SchemeService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restoration M4 (audit D3): scheme maturity previously fired ONLY when the
 * installment count was reached (in recordPayment). An enrollment that reached
 * its maturity_date under-paid stayed 'active' forever and never matured. This
 * daily command closes that gap: it matures every active enrollment whose
 * maturity_date has passed, accruing the bonus only when fully paid (the same
 * rule the payment path already uses).
 */
class ProcessSchemeMaturity extends Command
{
    protected $signature = 'schemes:process-maturity
                            {--shop= : Scope to one shop ID (omit for all active shops)}';

    protected $description = 'Mature scheme enrollments whose term (maturity_date) has been reached. Bonus accrues only when fully paid.';

    public function handle(SchemeService $service): int
    {
        $shopFilter = $this->option('shop');
        $shopIds = $shopFilter !== null
            ? [(int) $shopFilter]
            : DB::table('shops')->whereRaw('is_active = TRUE')->pluck('id')->all();

        $totalMatured = 0;

        foreach ($shopIds as $shopId) {
            try {
                $matured = TenantContext::runFor((int) $shopId, fn () =>
                    $service->processMaturedEnrollments((int) $shopId)
                );
                if ($matured > 0) {
                    $this->info("Shop {$shopId}: matured {$matured} enrollment(s).");
                }
                $totalMatured += $matured;
            } catch (\Throwable $e) {
                // One shop's lock/guard failure must not halt the rest.
                $this->warn("Shop {$shopId}: skipped ({$e->getMessage()}).");
            }
        }

        $this->info("Done. {$totalMatured} enrollment(s) matured.");
        return self::SUCCESS;
    }
}

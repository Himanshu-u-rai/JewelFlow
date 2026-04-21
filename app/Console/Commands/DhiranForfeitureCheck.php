<?php

namespace App\Console\Commands;

use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use Illuminate\Console\Command;

class DhiranForfeitureCheck extends Command
{
    protected $signature = 'dhiran:forfeiture-check';
    protected $description = 'Check for loans eligible for forfeiture and log warnings';

    public function handle(): int
    {
        $enabledSettings = DhiranSettings::where('is_enabled', true)->get();

        $totalNeedNotice = 0;
        $totalReadyForfeiture = 0;
        $errors = 0;

        $this->info("Found {$enabledSettings->count()} shop(s) with Dhiran enabled.");

        foreach ($enabledSettings as $settings) {
            try {
                $graceDays = $settings->grace_period_days ?? 30;
                $noticeDays = $settings->forfeiture_notice_days ?? 30;

                // 1) Active loans past maturity + grace + forfeiture_notice_days with NO notice sent
                $needNotice = DhiranLoan::where('shop_id', $settings->shop_id)
                    ->where('status', 'active')
                    ->whereNotNull('maturity_date')
                    ->whereNull('forfeiture_notice_sent_at')
                    ->whereDate('maturity_date', '<', now()->subDays($graceDays + $noticeDays))
                    ->with('customer')
                    ->get();

                foreach ($needNotice as $loan) {
                    $customerName = $loan->customer?->name ?? 'Unknown';
                    $this->warn("Shop #{$settings->shop_id} | NEEDS FORFEITURE NOTICE: Loan #{$loan->loan_number} ({$customerName}) — {$loan->daysOverdue()} days overdue, no notice sent");
                }
                $totalNeedNotice += $needNotice->count();

                // 2) Loans where forfeiture notice WAS sent and notice period has elapsed, but not yet forfeited
                $readyForfeiture = DhiranLoan::where('shop_id', $settings->shop_id)
                    ->where('status', 'active')
                    ->whereNotNull('forfeiture_notice_sent_at')
                    ->whereNull('forfeited_at')
                    ->whereDate('forfeiture_notice_sent_at', '<', now()->subDays($noticeDays))
                    ->with('customer')
                    ->get();

                foreach ($readyForfeiture as $loan) {
                    $customerName = $loan->customer?->name ?? 'Unknown';
                    $daysSinceNotice = (int) abs($loan->forfeiture_notice_sent_at->diffInDays(now()));
                    $this->warn("Shop #{$settings->shop_id} | READY FOR FORFEITURE: Loan #{$loan->loan_number} ({$customerName}) — notice sent {$daysSinceNotice} days ago");
                }
                $totalReadyForfeiture += $readyForfeiture->count();

                $this->info("Shop #{$settings->shop_id}: {$needNotice->count()} need notice, {$readyForfeiture->count()} ready for forfeiture");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Shop #{$settings->shop_id}: forfeiture check failed — {$e->getMessage()}");
                \Log::error("dhiran:forfeiture-check failed for shop #{$settings->shop_id}", ['exception' => $e]);
            }
        }

        $this->info("Done. Need forfeiture notice: {$totalNeedNotice}, Ready for forfeiture: {$totalReadyForfeiture}. Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

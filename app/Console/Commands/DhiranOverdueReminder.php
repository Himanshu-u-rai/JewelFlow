<?php

namespace App\Console\Commands;

use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class DhiranOverdueReminder extends Command
{
    protected $signature = 'dhiran:overdue-reminders';
    protected $description = 'Send overdue loan reminders (placeholder for SMS/notification)';

    public function handle(): int
    {
        // Cross-shop discovery bypasses the tenant scope (fails closed in console);
        // each shop's loan queries then run inside its own TenantContext.
        $enabledSettings = DhiranSettings::withoutGlobalScope('shop')
            ->whereRaw('is_enabled IS TRUE')
            ->whereRaw('sms_reminders_enabled IS TRUE')
            ->get();

        $totalApproaching = 0;
        $totalOverdue = 0;
        $errors = 0;

        $this->info("Found {$enabledSettings->count()} shop(s) with Dhiran + SMS reminders enabled.");

        foreach ($enabledSettings as $settings) {
            try {
                [$approachingCount, $overdueCount] = TenantContext::runFor($settings->shop_id, function () use ($settings) {
                $reminderDays = $settings->reminder_days_before_due ?? 7;

                // Loans approaching maturity (within reminder_days_before_due)
                $approaching = DhiranLoan::where('shop_id', $settings->shop_id)
                    ->where('status', 'active')
                    ->whereNotNull('maturity_date')
                    ->whereDate('maturity_date', '>', now())
                    ->whereDate('maturity_date', '<=', now()->addDays($reminderDays))
                    ->with('customer')
                    ->get();

                foreach ($approaching as $loan) {
                    $customerName = $loan->customer?->name ?? 'Unknown';
                    $this->warn("Shop #{$settings->shop_id} | APPROACHING: Loan #{$loan->loan_number} ({$customerName}) — matures {$loan->maturity_date->format('Y-m-d')} ({$loan->daysTillMaturity()} days)");
                    // TODO: Send SMS/notification to customer
                }

                // Already overdue loans
                $overdue = DhiranLoan::where('shop_id', $settings->shop_id)
                    ->overdue()
                    ->with('customer')
                    ->get();

                foreach ($overdue as $loan) {
                    $customerName = $loan->customer?->name ?? 'Unknown';
                    $this->warn("Shop #{$settings->shop_id} | OVERDUE: Loan #{$loan->loan_number} ({$customerName}) — {$loan->daysOverdue()} days overdue");
                    // TODO: Send SMS/notification to customer
                }

                    return [$approaching->count(), $overdue->count()];
                });

                $totalApproaching += $approachingCount;
                $totalOverdue += $overdueCount;
                $this->info("Shop #{$settings->shop_id}: {$approachingCount} approaching, {$overdueCount} overdue");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Shop #{$settings->shop_id}: reminder check failed — {$e->getMessage()}");
                \Log::error("dhiran:overdue-reminders failed for shop #{$settings->shop_id}", ['exception' => $e]);
            }
        }

        $this->info("Done. Approaching maturity: {$totalApproaching}, Overdue: {$totalOverdue}. Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

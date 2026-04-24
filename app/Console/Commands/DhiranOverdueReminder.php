<?php

namespace App\Console\Commands;

use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use Illuminate\Console\Command;

class DhiranOverdueReminder extends Command
{
    protected $signature = 'dhiran:overdue-reminders';
    protected $description = 'Send overdue loan reminders (placeholder for SMS/notification)';

    public function handle(): int
    {
        $enabledSettings = DhiranSettings::where('is_enabled', true)
            ->where('sms_reminders_enabled', true)
            ->get();

        $totalApproaching = 0;
        $totalOverdue = 0;
        $errors = 0;

        $this->info("Found {$enabledSettings->count()} shop(s) with Dhiran + SMS reminders enabled.");

        foreach ($enabledSettings as $settings) {
            try {
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
                $totalApproaching += $approaching->count();

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
                $totalOverdue += $overdue->count();

                $this->info("Shop #{$settings->shop_id}: {$approaching->count()} approaching, {$overdue->count()} overdue");
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

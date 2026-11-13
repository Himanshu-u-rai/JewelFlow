<?php

namespace App\Console\Commands;

use App\Models\InstallmentPlan;
use App\Services\InstallmentService;
use Illuminate\Console\Command;

/**
 * Support correction (CONSTITUTION §3 Lane 2): reopens the named EMI plans that the old EMI rule closed while money
 * was still owed, through InstallmentService::reopenEarlyClosedPlan (audited, with a reason). Only plans named on the
 * command line are touched; find them with emi:early-closed first.
 */
class EmiReopenEarlyClosed extends Command
{
    protected $signature = 'emi:reopen-early-closed
                            {plan* : Plan ids, as listed by emi:early-closed}
                            {--reason= : Why (kept in the audit log)}';

    protected $description = 'Reopen named EMI plans closed while money was still owed (audited).';

    public function handle(InstallmentService $installments): int
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('Give a --reason; it is kept in the audit log.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($this->argument('plan') as $id) {
            $plan = InstallmentPlan::withoutTenant()->find((int) $id);
            if (! $plan) {
                $this->error("plan #{$id}: not found");
                $failed++;
                continue;
            }
            try {
                $reopened = $installments->reopenEarlyClosedPlan($plan, $reason, null);
                $this->info(sprintf('plan #%d reopened: owed %.2f, EMIs paid %d/%d, next due %s',
                    $reopened->id, $reopened->remaining_amount, $reopened->emis_paid, $reopened->total_emis,
                    $reopened->next_due_date?->toDateString()));
            } catch (\LogicException $e) {
                $this->error("plan #{$id}: " . $e->getMessage());
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

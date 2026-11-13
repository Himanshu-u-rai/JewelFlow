<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only (CONSTITUTION §2 F5): lists EMI plans marked completed while money is still owed, which the old EMI rule
 * produced (audit H2). Exits 1 when it finds any. Correct them with emi:reopen-early-closed.
 */
class EmiEarlyClosedCheck extends Command
{
    protected $signature = 'emi:early-closed {--shop= : Limit to one shop_id}';

    protected $description = 'List EMI plans closed while money was still owed (read-only).';

    public function handle(): int
    {
        $shopId = $this->option('shop') !== null ? (int) $this->option('shop') : null;

        $rows = DB::table('installment_plans as p')
            ->leftJoin('installment_payments as pay', 'pay.plan_id', '=', 'p.id')
            ->where('p.status', 'completed')
            ->when($shopId, fn ($q) => $q->where('p.shop_id', $shopId))
            ->groupBy('p.id', 'p.shop_id', 'p.invoice_id', 'p.total_payable')
            ->havingRaw('p.total_payable - coalesce(sum(pay.amount), 0) > 0.01')
            ->orderBy('p.id')
            ->get(['p.id', 'p.shop_id', 'p.invoice_id', 'p.total_payable', DB::raw('coalesce(sum(pay.amount), 0) as paid')]);

        if ($rows->isEmpty()) {
            $this->info('No EMI plan is closed with money still owed.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $owed = round((float) $row->total_payable - (float) $row->paid, 2);
            $this->warn(sprintf('plan #%d (shop %d, invoice %d): payable %.2f, paid %.2f, still owed %.2f',
                $row->id, $row->shop_id, $row->invoice_id, $row->total_payable, $row->paid, $owed));
        }
        $this->error($rows->count() . ' EMI plan(s) closed with money owed. Reopen each with: php artisan emi:reopen-early-closed <plan ids> --reason="..."');

        return self::FAILURE;
    }
}

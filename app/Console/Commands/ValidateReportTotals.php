<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\CreditNote;
use App\Reporting\GstReportingService;
use App\Reporting\ReportPeriod;
use App\Reporting\SalesService;
use App\Reporting\TaxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * reports:validate — read-only reconciliation of report totals against the
 * underlying persisted accounting data (audit §9 / plan §1.5).
 *
 * It proves that the reporting layer does not drift from accounting truth.
 * Makes NO writes. Exits 1 if any invariant fails, 0 if clean — same contract
 * as vault:reconcile / returns:validate, so it can be scheduled and gated in CI.
 *
 * Current checks (extended as more reporting services land):
 *   GST-1  Service GST collected == direct SUM(invoices.gst) over the same scope
 *   GST-2  Per finalized invoice: cgst+sgst+igst == gst (split integrity)
 *   GST-3  Per credit note:        cgst+sgst+igst == gst
 *   GST-4  Net liability == GST collected − GST reversed by credit notes
 */
class ValidateReportTotals extends Command
{
    protected $signature = 'reports:validate
                            {--shop= : Scope to one shop ID (omit for all active shops)}
                            {--month= : Month (1-12), defaults to current}
                            {--year= : Year, defaults to current}';

    protected $description = 'Verify report totals reconcile to persisted accounting data. Read-only — never writes. Exits 1 on drift.';

    private const TOLERANCE = 0.01;

    public function handle(GstReportingService $gst, TaxService $tax, SalesService $sales, \App\Reporting\ReceivablesService $receivables, \App\Reporting\InventoryService $inventory, \App\Reporting\KarigarService $karigar, \App\Reporting\AuditService $audit): int
    {
        $shopFilter = $this->option('shop');
        $shopIds = $shopFilter !== null
            ? [(int) $shopFilter]
            : DB::table('shops')->whereRaw('is_active = TRUE')->pluck('id')->all();

        if (empty($shopIds)) {
            $this->error('No active shops found.');
            return 1;
        }

        $period = ReportPeriod::month($this->option('year'), $this->option('month'));
        $this->line("Validating report totals for {$period->label()}");

        $failures = 0;

        foreach ($shopIds as $shopId) {
            $failures += $this->validateShop((int) $shopId, $period, $gst);
            $failures += $this->validateTaxPack((int) $shopId, $period, $gst, $tax);
            $failures += $this->validateReconciliation((int) $shopId, $period, $gst, $sales);
            $failures += $this->validateReceivables((int) $shopId, $receivables);
            $failures += $this->validateInventory((int) $shopId, $period, $inventory);
            $failures += $this->validateKarigar((int) $shopId, $karigar);
            $failures += $this->validateOperator((int) $shopId, $period, $audit, $gst);
        }

        $this->newLine();

        if ($failures === 0) {
            $this->info('All report totals reconcile to accounting data.');
            return 0;
        }

        $this->warn("{$failures} reconciliation failure(s) detected.");
        return 1;
    }

    private function validateShop(int $shopId, ReportPeriod $period, GstReportingService $gst): int
    {
        $failures = 0;
        $data = $gst->summary($shopId, $period);
        [$start, $end] = $period->bounds();

        // GST-1 — service total matches a direct sum over the same canonical scope.
        $directGst = (float) Invoice::withoutTenant()
            ->where('shop_id', $shopId)
            ->salesIn($period)
            ->sum('gst');

        $failures += $this->assert(
            $shopId,
            'GST-1 service-vs-direct GST collected',
            abs($data->gstCollected - round($directGst, 2)) <= self::TOLERANCE,
            "service={$data->gstCollected} direct=" . round($directGst, 2)
        );

        // GST-2 — split integrity on finalized invoices (only rows that carry a split).
        $invoiceSplitDrift = Invoice::withoutTenant()
            ->where('shop_id', $shopId)
            ->salesIn($period)
            ->whereNotNull('cgst_amount')
            ->whereRaw('ABS(COALESCE(cgst_amount,0) + COALESCE(sgst_amount,0) + COALESCE(igst_amount,0) - gst) > ?', [self::TOLERANCE])
            ->count();

        $failures += $this->assert(
            $shopId,
            'GST-2 invoice CGST+SGST+IGST == GST',
            $invoiceSplitDrift === 0,
            "{$invoiceSplitDrift} invoice(s) with split drift"
        );

        // GST-3 — split integrity on credit notes.
        $cnSplitDrift = CreditNote::withoutTenant()
            ->where('shop_id', $shopId)
            ->whereBetween('issued_at', [$start, $end])
            ->whereNotNull('cgst_amount')
            ->whereRaw('ABS(COALESCE(cgst_amount,0) + COALESCE(sgst_amount,0) + COALESCE(igst_amount,0) - gst) > ?', [self::TOLERANCE])
            ->count();

        $failures += $this->assert(
            $shopId,
            'GST-3 credit-note CGST+SGST+IGST == GST',
            $cnSplitDrift === 0,
            "{$cnSplitDrift} credit note(s) with split drift"
        );

        // GST-4 — net liability identity.
        $expectedNet = round($data->gstCollected - $data->cnGstReversed, 2);
        $failures += $this->assert(
            $shopId,
            'GST-4 net liability == collected − reversed',
            abs($data->netGstLiability - $expectedNet) <= self::TOLERANCE,
            "net={$data->netGstLiability} expected={$expectedNet}"
        );

        return $failures;
    }

    /**
     * CA tax-pack invariants (Phase 2 M1). GSTR-1 / GSTR-3B / CN-register must
     * reconcile to the canonical GST summary — they consume it, so any drift
     * means a re-aggregation slipped in.
     */
    private function validateTaxPack(int $shopId, ReportPeriod $period, GstReportingService $gst, TaxService $tax): int
    {
        $failures = 0;
        $summary = $gst->summary($shopId, $period);
        $gstr1   = $tax->gstr1($shopId, $period);
        $gstr3b  = $tax->gstr3b($shopId, $period);
        $register = $tax->creditNoteRegister($shopId, $period);

        // GST-5 — GSTR-1 B2B + B2CS taxable sums to the GST report taxable.
        $b2bTaxable  = (float) $gstr1->b2b->sum('taxable');
        $b2csTaxable = (float) $gstr1->b2cs->sum('taxable');
        $failures += $this->assert(
            $shopId,
            'GST-5 GSTR-1 B2B+B2CS taxable == GST taxable',
            abs(($b2bTaxable + $b2csTaxable) - $summary->taxableAmount) <= self::TOLERANCE,
            'gstr1=' . round($b2bTaxable + $b2csTaxable, 2) . ' summary=' . $summary->taxableAmount
        );

        // GST-6 — GSTR-3B net GST == canonical net liability.
        $failures += $this->assert(
            $shopId,
            'GST-6 GSTR-3B net == liability net',
            abs($gstr3b->netGst - $summary->netGstLiability) <= self::TOLERANCE,
            'gstr3b=' . $gstr3b->netGst . ' liability=' . $summary->netGstLiability
        );

        // GST-7 — CN register GST reversed == GST report CN reversed.
        $failures += $this->assert(
            $shopId,
            'GST-7 CN register == GST CN reversed',
            abs($register->totalGst - $summary->cnGstReversed) <= self::TOLERANCE,
            'register=' . $register->totalGst . ' summary=' . $summary->cnGstReversed
        );

        return $failures;
    }

    /**
     * Reconciliation pack invariants (Phase 2 M2). Payment reconciliation must
     * agree with the canonical sale scope, and its mode breakdown must sum to
     * the collected total.
     */
    private function validateReconciliation(int $shopId, ReportPeriod $period, GstReportingService $gst, SalesService $sales): int
    {
        $failures = 0;
        $summary = $gst->summary($shopId, $period);
        $recon = $sales->paymentReconciliation($shopId, $period);

        // PAY-1 — same set of finalized invoices as the GST report.
        $failures += $this->assert(
            $shopId,
            'PAY-1 payment-recon invoice total == GST total sales',
            abs($recon->invoiceTotal - $summary->totalSales) <= self::TOLERANCE,
            'recon=' . $recon->invoiceTotal . ' gst=' . $summary->totalSales
        );

        // PAY-2 — collected equals the sum of the mode breakdown.
        $modeSum = round((float) $recon->modeBreakdown->sum(), 2);
        $failures += $this->assert(
            $shopId,
            'PAY-2 collected == sum of payment modes',
            abs($recon->collected - $modeSum) <= self::TOLERANCE,
            'collected=' . $recon->collected . ' modeSum=' . $modeSum
        );

        // PAY-3 — invoice counts agree.
        $failures += $this->assert(
            $shopId,
            'PAY-3 payment-recon count == GST invoice count',
            $recon->invoiceCount === $summary->invoiceCount,
            'recon=' . $recon->invoiceCount . ' gst=' . $summary->invoiceCount
        );

        return $failures;
    }

    /**
     * Receivables invariants (Phase 2 M3, report #8 — dues aging). Point-in-time,
     * so independent of the period being validated.
     */
    private function validateReceivables(int $shopId, \App\Reporting\ReceivablesService $receivables): int
    {
        $failures = 0;
        $dues = $receivables->duesAging($shopId);

        // DUE-1 — the four age buckets sum to the reported total.
        $bucketSum = round(
            $dues->bucketCurrent + $dues->bucket3160 + $dues->bucket6190 + $dues->bucket90plus,
            2
        );
        $failures += $this->assert(
            $shopId,
            'DUE-1 dues buckets sum to total',
            abs($bucketSum - $dues->totalOutstanding) <= self::TOLERANCE,
            'buckets=' . $bucketSum . ' total=' . $dues->totalOutstanding
        );

        // DUE-2 — total matches an INDEPENDENT recompute straight from the
        // persisted tables: Σ max(0, finalized invoice total − collected).
        $independent = round((float) DB::table('invoices as i')
            ->where('i.shop_id', $shopId)
            ->where('i.status', \App\Models\Invoice::STATUS_FINALIZED)
            ->leftJoinSub(
                DB::table('invoice_payments')->select('invoice_id', DB::raw('SUM(amount) as collected'))
                    ->where('shop_id', $shopId)->groupBy('invoice_id'),
                'p', 'p.invoice_id', '=', 'i.id'
            )
            ->selectRaw('COALESCE(SUM(GREATEST(i.total - COALESCE(p.collected, 0), 0)), 0) as due')
            ->value('due'), 2);

        $failures += $this->assert(
            $shopId,
            'DUE-2 dues total == invoices outstanding (independent recompute)',
            abs($independent - $dues->totalOutstanding) <= self::TOLERANCE,
            'independent=' . $independent . ' service=' . $dues->totalOutstanding
        );

        // #9 EMI visibility.
        $emi = $receivables->emiVisibility($shopId);

        // EMI-1 — per-plan rows sum to the reported outstanding.
        $rowSum = round((float) $emi->rows->sum('remaining'), 2);
        $failures += $this->assert(
            $shopId,
            'EMI-1 plan rows sum to total outstanding',
            abs($rowSum - $emi->totalOutstanding) <= self::TOLERANCE,
            'rows=' . $rowSum . ' total=' . $emi->totalOutstanding
        );

        // EMI-2 — total matches an independent Σ remaining_amount of active plans.
        $emiIndependent = round((float) DB::table('installment_plans')
            ->where('shop_id', $shopId)->where('status', 'active')
            ->sum('remaining_amount'), 2);
        $failures += $this->assert(
            $shopId,
            'EMI-2 outstanding == active plans remaining (independent recompute)',
            abs($emiIndependent - $emi->totalOutstanding) <= self::TOLERANCE,
            'independent=' . $emiIndependent . ' service=' . $emi->totalOutstanding
        );

        // #10 Scheme liability.
        $sch = $receivables->schemeLiability($shopId);

        // SCH-1 — per-enrollment balances sum to the reported liability.
        $schRowSum = round((float) $sch->rows->sum('current_balance'), 2);
        $failures += $this->assert(
            $shopId,
            'SCH-1 enrollment balances sum to total liability',
            abs($schRowSum - $sch->totalLiability) <= self::TOLERANCE,
            'rows=' . $schRowSum . ' total=' . $sch->totalLiability
        );

        // SCH-2 — liability == independent Σ latest ledger balance for non-terminal enrollments.
        $schIndependent = round((float) DB::table('scheme_enrollments as se')
            ->where('se.shop_id', $shopId)
            ->whereIn('se.status', ['active', 'matured'])
            ->leftJoin('scheme_ledger_entries as e', 'e.id', '=', DB::raw(
                '(SELECT MAX(id) FROM scheme_ledger_entries le WHERE le.scheme_enrollment_id = se.id)'
            ))
            ->selectRaw('COALESCE(SUM(COALESCE(e.balance_after, 0)), 0) as liab')
            ->value('liab'), 2);
        $failures += $this->assert(
            $shopId,
            'SCH-2 liability == non-terminal enrollment ledger balance (independent)',
            abs($schIndependent - $sch->totalLiability) <= self::TOLERANCE,
            'independent=' . $schIndependent . ' service=' . $sch->totalLiability
        );

        // #11 Metal / old-gold liability (fine grams).
        $metal = $receivables->metalLiability($shopId);

        // MTL-1 — per-customer gross deposits sum to the reported gross total.
        $depSum = round((float) $metal->rows->sum('fine_deposited'), 4);
        $failures += $this->assert(
            $shopId,
            'MTL-1 per-customer deposits sum to gross deposited',
            abs($depSum - $metal->totalDeposited) <= self::TOLERANCE,
            'rows=' . $depSum . ' total=' . $metal->totalDeposited
        );

        // MTL-2 — advance liability is non-negative and covered by gold on hand
        // (the customer_advance lot is a subset of all lots).
        $failures += $this->assert(
            $shopId,
            'MTL-2 advance liability >= 0 and <= vault on hand',
            $metal->totalAdvanceLiability >= -self::TOLERANCE
                && $metal->totalAdvanceLiability <= $metal->vaultOnHandFine + self::TOLERANCE,
            'liability=' . $metal->totalAdvanceLiability . ' onHand=' . $metal->vaultOnHandFine
        );

        return $failures;
    }

    /**
     * Inventory invariants (Phase 2 M4, #12 — dead stock aging). Point-in-time.
     */
    private function validateInventory(int $shopId, ReportPeriod $period, \App\Reporting\InventoryService $inventory): int
    {
        $failures = 0;
        $ds = $inventory->deadStock($shopId);

        // DS-1 — the four age buckets sum to the reported total value.
        $bucketSum = round($ds->freshValue + $ds->agingValue + $ds->staleValue + $ds->deadValue, 2);
        $failures += $this->assert(
            $shopId,
            'DS-1 dead-stock buckets sum to total value',
            abs($bucketSum - $ds->totalValue) <= self::TOLERANCE,
            'buckets=' . $bucketSum . ' total=' . $ds->totalValue
        );

        // DS-2 — total matches an independent Σ cost_price of in_stock items.
        $independent = round((float) DB::table('items')
            ->where('shop_id', $shopId)->where('status', 'in_stock')
            ->sum(DB::raw('COALESCE(cost_price, 0)')), 2);
        $failures += $this->assert(
            $shopId,
            'DS-2 total value == in_stock cost (independent recompute)',
            abs($independent - $ds->totalValue) <= self::TOLERANCE,
            'independent=' . $independent . ' service=' . $ds->totalValue
        );

        // #14 Purchase efficiency — per-metal premium sums to the total.
        $pe = $inventory->purchaseEfficiency($shopId, $period);
        $peRowSum = round((float) $pe->rows->sum('premium'), 2);
        $failures += $this->assert(
            $shopId,
            'PUR-1 per-metal premium sums to total',
            abs($peRowSum - $pe->totalPremium) <= self::TOLERANCE,
            'rows=' . $peRowSum . ' total=' . $pe->totalPremium
        );

        return $failures;
    }

    /**
     * Karigar settlement invariants (Phase 2 M4, #13). Point-in-time.
     */
    private function validateKarigar(int $shopId, \App\Reporting\KarigarService $karigar): int
    {
        $failures = 0;
        $s = $karigar->settlement($shopId);

        // KAR-1 — per-karigar payables sum to the reported total.
        $rowSum = round((float) $s->rows->sum('outstanding_payable'), 2);
        $failures += $this->assert(
            $shopId,
            'KAR-1 karigar payables sum to total',
            abs($rowSum - $s->totalOutstandingPayable) <= self::TOLERANCE,
            'rows=' . $rowSum . ' total=' . $s->totalOutstandingPayable
        );

        // KAR-2 — total payable == independent Σ(total_after_tax − amount_paid).
        $independent = round((float) DB::table('karigar_invoices')
            ->where('shop_id', $shopId)
            ->selectRaw('COALESCE(SUM(total_after_tax - COALESCE(amount_paid,0)), 0) as payable')
            ->value('payable'), 2);
        $failures += $this->assert(
            $shopId,
            'KAR-2 payable == invoiced − paid (independent recompute)',
            abs($independent - $s->totalOutstandingPayable) <= self::TOLERANCE,
            'independent=' . $independent . ' service=' . $s->totalOutstandingPayable
        );

        return $failures;
    }

    /**
     * Operator performance invariant (#16). The per-operator sales total
     * (including the Unattributed bucket) must equal the canonical GST total
     * sales for the period.
     */
    private function validateOperator(int $shopId, ReportPeriod $period, \App\Reporting\AuditService $audit, GstReportingService $gst): int
    {
        $op = $audit->operatorPerformance($shopId, $period);
        $summary = $gst->summary($shopId, $period);

        return $this->assert(
            $shopId,
            'OP-1 operator sales total == GST total sales',
            abs($op->totalSales - $summary->totalSales) <= self::TOLERANCE,
            'operator=' . $op->totalSales . ' gst=' . $summary->totalSales
        );
    }

    private function assert(int $shopId, string $name, bool $ok, string $detail): int
    {
        if ($ok) {
            $this->line("  Shop {$shopId}: <info>✓</info> {$name}");
            return 0;
        }

        $this->warn("  Shop {$shopId}: ✗ {$name} — {$detail}");
        return 1;
    }
}

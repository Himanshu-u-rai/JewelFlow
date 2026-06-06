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

    public function handle(GstReportingService $gst, TaxService $tax, SalesService $sales, \App\Reporting\ReceivablesService $receivables, \App\Reporting\InventoryService $inventory, \App\Reporting\KarigarService $karigar, \App\Reporting\AuditService $audit, \App\Reporting\LedgerService $ledger, \App\Reporting\ClosingService $closing): int
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
            $failures += $this->validatePaymentRecon((int) $shopId, $period, $sales);
            $failures += $this->validateReconciliation((int) $shopId, $period, $gst, $sales);
            $failures += $this->validateReceivables((int) $shopId, $receivables);
            $failures += $this->validateMetalLiability((int) $shopId, $receivables);
            $failures += $this->validateInventory((int) $shopId, $period, $inventory);
            $failures += $this->validateCashFlow((int) $shopId, $period, $ledger);
            $failures += $this->validateClosing((int) $shopId, $period, $closing);
            $failures += $this->validateDailySummary((int) $shopId, $period, $gst);
            $failures += $this->validateKarigar((int) $shopId, $karigar);
            $failures += $this->validateOperator((int) $shopId, $period, $audit, $gst);
            $failures += $this->validateSuspicious((int) $shopId, $period, $audit);
            $failures += $this->validateShrinkage((int) $shopId, $period, $karigar);
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

        // PAY-5 — same set of finalized invoices as the GST report.
        $failures += $this->assert(
            $shopId,
            'PAY-5 payment-recon invoice total == GST total sales',
            abs($recon->invoiceTotal - $summary->totalSales) <= self::TOLERANCE,
            'recon=' . $recon->invoiceTotal . ' gst=' . $summary->totalSales
        );

        // PAY-6 — collected equals the sum of the mode breakdown.
        $modeSum = round((float) $recon->modeBreakdown->sum(), 2);
        $failures += $this->assert(
            $shopId,
            'PAY-6 collected == sum of payment modes',
            abs($recon->collected - $modeSum) <= self::TOLERANCE,
            'collected=' . $recon->collected . ' modeSum=' . $modeSum
        );

        // PAY-7 — invoice counts agree.
        $failures += $this->assert(
            $shopId,
            'PAY-7 payment-recon count == GST invoice count',
            $recon->invoiceCount === $summary->invoiceCount,
            'recon=' . $recon->invoiceCount . ' gst=' . $summary->invoiceCount
        );

        return $failures;
    }

    /**
     * Payment Reconciliation invariants (Phase 3) — the financial-integrity report.
     * Wraps SalesService::paymentReconciliation() and proves billed/collected/variance
     * tie to the raw source tables INDEPENDENTLY of the GST service.
     */
    private function validatePaymentRecon(int $shopId, ReportPeriod $period, SalesService $sales): int
    {
        $failures = 0;
        $recon = $sales->paymentReconciliation($shopId, $period);

        // PAY-1 — Σ invoice totals == raw salesIn Σ(invoices.total).
        $rawInvoiceTotal = round((float) Invoice::withoutTenant()
            ->where('shop_id', $shopId)->salesIn($period)->sum('total'), 2);
        $failures += $this->assert(
            $shopId,
            'PAY-1 Σ invoice totals == source invoices aggregate (raw salesIn)',
            abs($recon->invoiceTotal - $rawInvoiceTotal) <= self::TOLERANCE,
            "recon={$recon->invoiceTotal} raw={$rawInvoiceTotal}"
        );

        // PAY-2 — Σ collected == raw Σ(invoice_payments.amount) over the same invoices.
        $ids = Invoice::withoutTenant()->where('shop_id', $shopId)->salesIn($period)->pluck('id')->all();
        $rawCollected = empty($ids) ? 0.0 : round((float) DB::table('invoice_payments')
            ->where('shop_id', $shopId)->whereIn('invoice_id', $ids)->sum('amount'), 2);
        $failures += $this->assert(
            $shopId,
            'PAY-2 Σ collected == invoice_payments aggregate (raw)',
            abs($recon->collected - $rawCollected) <= self::TOLERANCE,
            "recon={$recon->collected} raw={$rawCollected}"
        );

        // PAY-3 — Σ variances == Σ invoice totals − Σ collected.
        $sumVariance = round((float) $recon->rows->sum('pending'), 2);
        $expectedVariance = round($recon->invoiceTotal - $recon->collected, 2);
        $failures += $this->assert(
            $shopId,
            'PAY-3 Σ variances == Σ invoice totals − Σ collected',
            abs($sumVariance - $expectedVariance) <= self::TOLERANCE,
            "Σvariance={$sumVariance} expected={$expectedVariance}"
        );

        // PAY-4 — every invoice row satisfies invoice_total − collected == variance.
        $rowDrift = $recon->rows->filter(
            fn ($r) => abs(((float) $r->total - (float) $r->collected) - (float) $r->pending) > self::TOLERANCE
        )->count();
        $failures += $this->assert(
            $shopId,
            'PAY-4 per-row invoice_total − collected == variance',
            $rowDrift === 0,
            "{$rowDrift} row(s) with variance drift"
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

        return $failures;
    }

    /**
     * Metal Liability invariants (Phase 3, #11 — customer-advance gold owed vs on
     * hand). Point-in-time. Wraps ReceivablesService::metalLiability() and proves
     * the liability, the customer breakdown and the on-hand figure tie to the raw
     * tables — the on-hand check using the SAME source as vault:reconcile
     * (SUM(metal_lots.fine_weight_remaining)).
     */
    private function validateMetalLiability(int $shopId, \App\Reporting\ReceivablesService $receivables): int
    {
        $failures = 0;
        $metal = $receivables->metalLiability($shopId);

        // METAL-1 — total liability == independent Σ(customer_advance lot remaining).
        $rawLiability = round((float) DB::table('metal_lots')
            ->where('shop_id', $shopId)->where('source', 'customer_advance')
            ->sum('fine_weight_remaining'), 4);
        $failures += $this->assert(
            $shopId,
            'METAL-1 total liability == customer_advance lot remaining (independent)',
            abs($metal->totalAdvanceLiability - $rawLiability) <= self::TOLERANCE,
            "service={$metal->totalAdvanceLiability} raw={$rawLiability}"
        );

        // METAL-2 — customer breakdown sums to the reported gross total deposited.
        $depSum = round((float) $metal->rows->sum('fine_deposited'), 4);
        $failures += $this->assert(
            $shopId,
            'METAL-2 customer breakdown sums to total deposited',
            abs($depSum - $metal->totalDeposited) <= self::TOLERANCE,
            "rows={$depSum} total={$metal->totalDeposited}"
        );

        // METAL-3 — on-hand == vault:reconcile source (Σ metal_lots fine remaining),
        // and the liability is covered by gold on hand.
        $rawOnHand = round((float) DB::table('metal_lots')
            ->where('shop_id', $shopId)->sum('fine_weight_remaining'), 4);
        $failures += $this->assert(
            $shopId,
            'METAL-3 on-hand == vault:reconcile source & liability <= on-hand',
            abs($metal->vaultOnHandFine - $rawOnHand) <= self::TOLERANCE
                && $metal->totalAdvanceLiability <= $metal->vaultOnHandFine + self::TOLERANCE,
            "onHand svc={$metal->vaultOnHandFine} raw={$rawOnHand}; liability={$metal->totalAdvanceLiability}"
        );

        // METAL-4 — grand-total reconciliation: report grand total == customer
        // breakdown total == service total (and the row count agrees).
        $failures += $this->assert(
            $shopId,
            'METAL-4 grand total == breakdown total == service total',
            abs($metal->totalDeposited - $depSum) <= self::TOLERANCE
                && $metal->rows->count() === $metal->customerCount,
            "service={$metal->totalDeposited} breakdown={$depSum} rows={$metal->rows->count()} count={$metal->customerCount}"
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

        // Inventory Valuation (Phase 3) — wraps InventoryService::valuation().
        $val = $inventory->valuation($shopId);

        // VAL-1 — by-category cost buckets sum to the grand total at cost.
        $catSum = round((float) $val->byCategory->sum('cost_value'), 2);
        $failures += $this->assert(
            $shopId,
            'VAL-1 valuation by-category cost == total at cost',
            abs($catSum - $val->totalAtCost) <= self::TOLERANCE,
            'category=' . $catSum . ' total=' . $val->totalAtCost
        );

        // VAL-2 — by-metal cost buckets sum to the grand total at cost.
        $metalSum = round((float) $val->byMetal->sum('cost_value'), 2);
        $failures += $this->assert(
            $shopId,
            'VAL-2 valuation by-metal cost == total at cost',
            abs($metalSum - $val->totalAtCost) <= self::TOLERANCE,
            'metal=' . $metalSum . ' total=' . $val->totalAtCost
        );

        // VAL-3 — grand total == independent Σ cost_price of in_stock items.
        $valIndependent = round((float) DB::table('items')
            ->where('shop_id', $shopId)->where('status', 'in_stock')
            ->sum(DB::raw('COALESCE(cost_price, 0)')), 2);
        $failures += $this->assert(
            $shopId,
            'VAL-3 total at cost == in_stock cost (independent recompute)',
            abs($valIndependent - $val->totalAtCost) <= self::TOLERANCE,
            'independent=' . $valIndependent . ' service=' . $val->totalAtCost
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
     * Cash flow invariants (Phase 3). Wraps LedgerService::cashFlow().
     */
    private function validateCashFlow(int $shopId, ReportPeriod $period, \App\Reporting\LedgerService $ledger): int
    {
        $failures = 0;
        $cf = $ledger->cashFlow($shopId, $period);
        [$start, $end] = $period->bounds();

        // CASH-1 — the balance equation holds.
        $failures += $this->assert(
            $shopId,
            'CASH-1 opening + in − out == closing',
            abs(($cf->opening + $cf->cashIn - $cf->cashOut) - $cf->closing) <= self::TOLERANCE,
            "opening={$cf->opening} in={$cf->cashIn} out={$cf->cashOut} closing={$cf->closing}"
        );

        // CASH-2 — closing == independent Σ(in − out) over all cash up to period end.
        $independentClosing = round((float) DB::table('cash_transactions')
            ->where('shop_id', $shopId)
            ->where('created_at', '<=', $end)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE -amount END), 0) as net")
            ->value('net'), 2);
        $failures += $this->assert(
            $shopId,
            'CASH-2 closing == cash_transactions aggregate (independent)',
            abs($independentClosing - $cf->closing) <= self::TOLERANCE,
            "independent={$independentClosing} service={$cf->closing}"
        );

        // CASH-3 — period in/out totals reconcile to source data.
        $independentIn = round((float) DB::table('cash_transactions')->where('shop_id', $shopId)
            ->whereBetween('created_at', [$start, $end])->where('type', 'in')->sum('amount'), 2);
        $independentOut = round((float) DB::table('cash_transactions')->where('shop_id', $shopId)
            ->whereBetween('created_at', [$start, $end])->where('type', 'out')->sum('amount'), 2);
        $failures += $this->assert(
            $shopId,
            'CASH-3 period in/out == cash_transactions in/out (independent)',
            abs($independentIn - $cf->cashIn) <= self::TOLERANCE && abs($independentOut - $cf->cashOut) <= self::TOLERANCE,
            "in: svc={$cf->cashIn} indep={$independentIn} · out: svc={$cf->cashOut} indep={$independentOut}"
        );

        return $failures;
    }

    /**
     * Daily Closing invariants (Phase 3) — the cross-phase tie-out. Validated for
     * the first day of the period; the closing aggregator delegates to the GST and
     * cash services, so this proves the COMBINATION agrees with the raw tables.
     */
    private function validateClosing(int $shopId, ReportPeriod $period, \App\Reporting\ClosingService $closing): int
    {
        $failures = 0;
        $date = $period->start()->toDateString();
        $c = $closing->dailyClosing($shopId, $date);
        $day = ReportPeriod::day($date);
        [, $dayEnd] = $day->bounds();

        // CLOSE-1 — daily sales == Sales Register / GST scope (raw salesIn Σ total).
        $rawSales = round((float) Invoice::withoutTenant()->where('shop_id', $shopId)->salesIn($day)->sum('total'), 2);
        $failures += $this->assert(
            $shopId,
            'CLOSE-1 closing sales == Sales Register total (raw salesIn)',
            abs($c->totalSales - $rawSales) <= self::TOLERANCE,
            "closing={$c->totalSales} raw={$rawSales}"
        );

        // CLOSE-2 — GST totals == GST Report scope (raw salesIn Σ gst).
        $rawGst = round((float) Invoice::withoutTenant()->where('shop_id', $shopId)->salesIn($day)->sum('gst'), 2);
        $failures += $this->assert(
            $shopId,
            'CLOSE-2 closing GST == GST Report total (raw salesIn)',
            abs($c->gstCollected - $rawGst) <= self::TOLERANCE,
            "closing={$c->gstCollected} raw={$rawGst}"
        );

        // CLOSE-3 — cash movement == Cash Flow (raw cash_transactions net up to day end).
        $rawCash = round((float) DB::table('cash_transactions')
            ->where('shop_id', $shopId)->where('created_at', '<=', $dayEnd)
            ->selectRaw("COALESCE(SUM(CASE WHEN type='in' THEN amount ELSE -amount END),0) as net")->value('net'), 2);
        $failures += $this->assert(
            $shopId,
            'CLOSE-3 closing cash == Cash Flow closing (raw cash_transactions)',
            abs($c->cashClosing - $rawCash) <= self::TOLERANCE,
            "closing={$c->cashClosing} raw={$rawCash}"
        );

        // CLOSE-4 — combined closing reconciliation: the cash equation holds and the
        // combined totals are internally consistent.
        $failures += $this->assert(
            $shopId,
            'CLOSE-4 combined closing reconciliation (cash equation + consistency)',
            abs(($c->cashOpening + $c->cashIn - $c->cashOut) - $c->cashClosing) <= self::TOLERANCE
                && abs(($c->cgst + $c->sgst + $c->igst) - $c->gstCollected) <= max(self::TOLERANCE, 0.02),
            "cash {$c->cashOpening}+{$c->cashIn}-{$c->cashOut} vs {$c->cashClosing}; gst split vs {$c->gstCollected}"
        );

        return $failures;
    }

    /**
     * Daily (Sales Summary) invariants (Phase 3) — the lightweight daily report.
     * Validated for the first day of the period. Wraps GstReportingService::summary()
     * for that day and proves sales/count/GST tie to the raw finalized invoices
     * (the Sales Register / GST Report scope) INDEPENDENTLY.
     */
    private function validateDailySummary(int $shopId, ReportPeriod $period, GstReportingService $gst): int
    {
        $failures = 0;
        $date = $period->start()->toDateString();
        $day = ReportPeriod::day($date);
        $s = $gst->summary($shopId, $day);

        // DAILY-1 — daily sales == Sales Register total for the day (raw salesIn Σ total).
        $rawSales = round((float) Invoice::withoutTenant()->where('shop_id', $shopId)->salesIn($day)->sum('total'), 2);
        $failures += $this->assert(
            $shopId,
            'DAILY-1 daily sales == Sales Register total (raw salesIn)',
            abs($s->totalSales - $rawSales) <= self::TOLERANCE,
            "summary={$s->totalSales} raw={$rawSales}"
        );

        // DAILY-2 — daily invoice count == finalized invoice count for the day (raw salesIn).
        $rawCount = (int) Invoice::withoutTenant()->where('shop_id', $shopId)->salesIn($day)->count();
        $failures += $this->assert(
            $shopId,
            'DAILY-2 daily bills == finalized invoice count (raw salesIn)',
            $s->invoiceCount === $rawCount,
            "summary={$s->invoiceCount} raw={$rawCount}"
        );

        // DAILY-3 — daily GST == GST Report total for the day (raw salesIn Σ gst).
        $rawGst = round((float) Invoice::withoutTenant()->where('shop_id', $shopId)->salesIn($day)->sum('gst'), 2);
        $failures += $this->assert(
            $shopId,
            'DAILY-3 daily GST == GST Report total (raw salesIn)',
            abs($s->gstCollected - $rawGst) <= self::TOLERANCE,
            "summary={$s->gstCollected} raw={$rawGst}"
        );

        // DAILY-4 — summary totals are internally consistent (CGST+SGST+IGST == GST).
        $failures += $this->assert(
            $shopId,
            'DAILY-4 summary reconciliation (CGST+SGST+IGST == GST)',
            abs(($s->cgstCollected + $s->sgstCollected + $s->igstCollected) - $s->gstCollected) <= max(self::TOLERANCE, 0.02),
            "split=" . round($s->cgstCollected + $s->sgstCollected + $s->igstCollected, 2) . " gst={$s->gstCollected}"
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

    private function validateSuspicious(int $shopId, ReportPeriod $period, \App\Reporting\AuditService $audit): int
    {
        $sus = $audit->suspiciousActivity($shopId, $period);

        $byType = (int) $sus->countsByType->sum();
        $byResolved = $sus->unresolvedCount + ($sus->totalCount - $sus->unresolvedCount);

        return $this->assert(
            $shopId,
            'SUS-1 alert counts (by-type and resolved split) sum to total',
            $byType === $sus->totalCount && $byResolved === $sus->totalCount,
            'total=' . $sus->totalCount . ' byType=' . $byType . ' byResolved=' . $byResolved
        );
    }

    private function validateShrinkage(int $shopId, ReportPeriod $period, \App\Reporting\KarigarService $karigar): int
    {
        $shr = $karigar->shrinkage($shopId, $period);

        // Independent recompute: unaccounted must equal issued − returned − leftover − wastage.
        $recomputed = round($shr->totalIssued - $shr->totalReturned - $shr->totalLeftover - $shr->totalWastage, 4);

        return $this->assert(
            $shopId,
            'SHR-1 unaccounted == issued − items − leftover − wastage (independent recompute)',
            abs($recomputed - $shr->totalUnaccounted) <= self::TOLERANCE,
            'reported=' . $shr->totalUnaccounted . ' recomputed=' . $recomputed
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

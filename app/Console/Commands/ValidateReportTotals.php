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

    public function handle(GstReportingService $gst, TaxService $tax, SalesService $sales): int
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

<?php

namespace App\Reporting;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Reporting\Data\CnRegisterData;
use App\Reporting\Data\GstR1Data;
use App\Reporting\Data\GstR3bData;
use App\Reporting\Data\GstReportData;
use Illuminate\Support\Facades\DB;

/**
 * CA / compliance tax reporting (Phase 2 M1).
 *
 * Orchestrates over GstReportingService — it does NOT re-aggregate the same
 * sales/GST data. It adds GSTR-1 shaping (B2B vs B2CS + HSN), the GSTR-3B
 * support summary, and the credit/debit-note register. All figures are
 * persisted accounting values scoped by the canonical sale scope; GST is never
 * recomputed (CONSTITUTION §7).
 */
class TaxService
{
    public function __construct(private GstReportingService $gst) {}

    /** Tax liability summary == the canonical GST summary (net of returns). */
    public function liability(int $shopId, ReportPeriod $period): GstReportData
    {
        return $this->gst->summary($shopId, $period);
    }

    public function gstr1(int $shopId, ReportPeriod $period): GstR1Data
    {
        $base = $this->gst->summary($shopId, $period); // totals + CN rows (reused, not re-summed)

        $dateExpr = 'COALESCE(invoices.finalized_at, invoices.created_at)';

        // B2B — invoices carrying a buyer GSTIN.
        $b2b = Invoice::withoutTenant()
            ->where('invoices.shop_id', $shopId)
            ->salesIn($period)
            ->whereNotNull('invoices.buyer_gstin')
            ->whereRaw("TRIM(invoices.buyer_gstin) <> ''")
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select(
                'invoices.invoice_number',
                DB::raw("{$dateExpr} as doc_date"),
                'invoices.buyer_gstin',
                'invoices.place_of_supply_state_code',
                'invoices.gst_rate',
                'invoices.subtotal as taxable',
                DB::raw('COALESCE(invoices.cgst_amount, ROUND(invoices.gst / 2.0, 2)) as cgst'),
                DB::raw('COALESCE(invoices.sgst_amount, invoices.gst - ROUND(invoices.gst / 2.0, 2)) as sgst'),
                DB::raw('COALESCE(invoices.igst_amount, 0) as igst'),
                'invoices.gst',
                'invoices.total',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(customers.first_name, '') || ' ' || COALESCE(customers.last_name, '')), ''), 'Registered buyer') as customer_name")
            )
            ->orderBy('doc_date')
            ->get();

        // B2CS — everything else, aggregated rate × place-of-supply.
        $b2cs = Invoice::withoutTenant()
            ->where('invoices.shop_id', $shopId)
            ->salesIn($period)
            ->where(function ($q) {
                $q->whereNull('invoices.buyer_gstin')->orWhereRaw("TRIM(invoices.buyer_gstin) = ''");
            })
            ->select(
                'invoices.gst_rate',
                'invoices.place_of_supply_state_code',
                DB::raw('SUM(invoices.subtotal) as taxable'),
                DB::raw('SUM(COALESCE(invoices.cgst_amount, ROUND(invoices.gst / 2.0, 2))) as cgst'),
                DB::raw('SUM(COALESCE(invoices.sgst_amount, invoices.gst - ROUND(invoices.gst / 2.0, 2))) as sgst'),
                DB::raw('SUM(COALESCE(invoices.igst_amount, 0)) as igst'),
                DB::raw('SUM(invoices.gst) as gst'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('invoices.gst_rate', 'invoices.place_of_supply_state_code')
            ->orderBy('invoices.gst_rate')
            ->get();

        // HSN summary — line-level taxable + GST grouped by HSN.
        [$start, $end] = $period->bounds();
        $hsn = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status', Invoice::STATUS_FINALIZED)
            ->whereRaw("COALESCE(invoices.finalized_at, invoices.created_at) BETWEEN ? AND ?", [$start, $end])
            ->select(
                DB::raw("COALESCE(NULLIF(TRIM(invoice_items.hsn_code), ''), '—') as hsn_code"),
                DB::raw('SUM(invoice_items.line_total) as taxable'),
                DB::raw('SUM(COALESCE(invoice_items.gst_amount, 0)) as gst'),
                DB::raw('COUNT(*) as lines')
            )
            ->groupBy(DB::raw("COALESCE(NULLIF(TRIM(invoice_items.hsn_code), ''), '—')"))
            ->orderByDesc('taxable')
            ->get();

        return new GstR1Data(
            b2b: $b2b,
            b2cs: $b2cs,
            hsnSummary: $hsn,
            creditNotes: $base->creditNotes,
            taxable: $base->taxableAmount,
            cgst: $base->cgstCollected,
            sgst: $base->sgstCollected,
            igst: $base->igstCollected,
            totalGst: $base->gstCollected,
            invoiceCount: $base->invoiceCount,
            cnTaxable: $base->cnTaxableReversed,
            cnGst: $base->cnGstReversed,
            cnCount: $base->cnCount,
        );
    }

    public function gstr3b(int $shopId, ReportPeriod $period): GstR3bData
    {
        $s = $this->gst->summary($shopId, $period);

        return new GstR3bData(
            outwardTaxable: $s->taxableAmount,
            outwardCgst: $s->cgstCollected,
            outwardSgst: $s->sgstCollected,
            outwardIgst: $s->igstCollected,
            outwardGst: $s->gstCollected,
            cnTaxable: $s->cnTaxableReversed,
            cnGst: $s->cnGstReversed,
            netTaxable: round($s->taxableAmount - $s->cnTaxableReversed, 2),
            netGst: $s->netGstLiability,
            itc: 0.0,
        );
    }

    public function creditNoteRegister(int $shopId, ReportPeriod $period): CnRegisterData
    {
        [$start, $end] = $period->bounds();

        $rows = CreditNote::withoutTenant()
            ->where('credit_notes.shop_id', $shopId)
            ->whereBetween('credit_notes.issued_at', [$start, $end])
            ->leftJoin('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            ->leftJoin('customers', 'customers.id', '=', 'credit_notes.customer_id')
            ->select(
                'credit_notes.credit_note_number',
                'credit_notes.issued_at',
                DB::raw("CASE WHEN credit_notes.return_order_id IS NULL THEN 'full_cancellation' ELSE 'partial_return' END as cn_type"),
                'credit_notes.gst_rate',
                'credit_notes.subtotal as taxable',
                'credit_notes.gst',
                DB::raw('COALESCE(credit_notes.cgst_amount, ROUND(credit_notes.gst / 2.0, 2)) as cgst'),
                DB::raw('COALESCE(credit_notes.sgst_amount, credit_notes.gst - ROUND(credit_notes.gst / 2.0, 2)) as sgst'),
                DB::raw('COALESCE(credit_notes.igst_amount, 0) as igst'),
                'credit_notes.total',
                'invoices.invoice_number as original_invoice_number',
                'invoices.created_at as original_invoice_date',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(customers.first_name, '') || ' ' || COALESCE(customers.last_name, '')), ''), 'Walk-in') as customer_name")
            )
            ->orderBy('credit_notes.issued_at')
            ->get();

        return new CnRegisterData(
            rows: $rows,
            count: $rows->count(),
            totalTaxable: round((float) $rows->sum('taxable'), 2),
            totalGst: round((float) $rows->sum('gst'), 2),
            totalValue: round((float) $rows->sum('total'), 2),
        );
    }
}

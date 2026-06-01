<?php

namespace App\Reporting;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Reporting\Data\GstReportData;
use Illuminate\Support\Facades\DB;

/**
 * Canonical GST reporting.
 *
 * Fixes the audit's two highest-stakes findings (A1, A7):
 *   - Output tax is reduced by credit-note (return) reversals → net liability
 *     is correct, not overstated.
 *   - CGST / SGST / IGST are surfaced from the already-persisted split columns
 *     (`invoices.cgst_amount` etc., backfilled by the 2026_05_22 migrations),
 *     with a COALESCE(gst/2) fallback for any legacy null row.
 *
 * Authority: reads ONLY persisted accounting values. It never recomputes GST
 * (that lives behind GstRateResolver — CONSTITUTION §7). Sales are scoped by
 * the canonical accounting date, credit notes by issued_at (the period the
 * reversal legally lands in).
 *
 * Tenant-safe in both web and console: explicit shop_id + withoutTenant().
 */
class GstReportingService
{
    public function summary(int $shopId, ReportPeriod $period): GstReportData
    {
        [$start, $end] = $period->bounds();

        // ---- Output tax: finalized sales, grouped by GST rate ----
        $breakdown = Invoice::withoutTenant()
            ->where('shop_id', $shopId)
            ->salesIn($period)
            ->select(
                'gst_rate',
                DB::raw('SUM(subtotal) as taxable'),
                DB::raw('SUM(discount) as discount'),
                DB::raw('SUM(gst) as gst'),
                DB::raw('SUM(COALESCE(cgst_amount, ROUND(gst / 2.0, 2))) as cgst'),
                DB::raw('SUM(COALESCE(sgst_amount, gst - ROUND(gst / 2.0, 2))) as sgst'),
                DB::raw('SUM(COALESCE(igst_amount, 0)) as igst'),
                DB::raw('SUM(total) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('gst_rate')
            ->orderBy('gst_rate')
            ->get();

        $taxableAmount = round((float) $breakdown->sum('taxable'), 2);
        $totalDiscount = round((float) $breakdown->sum('discount'), 2);
        $gstCollected  = round((float) $breakdown->sum('gst'), 2);
        $cgstCollected = round((float) $breakdown->sum('cgst'), 2);
        $sgstCollected = round((float) $breakdown->sum('sgst'), 2);
        $igstCollected = round((float) $breakdown->sum('igst'), 2);
        $totalSales    = round((float) $breakdown->sum('total'), 2);
        $invoiceCount  = (int) $breakdown->sum('count');

        // ---- Credit notes (returns) issued in the period ----
        // Dated by issued_at — the reversal lands in the period it was processed,
        // never back-dated to the original invoice's period.
        $creditNotes = CreditNote::withoutTenant()
            ->where('credit_notes.shop_id', $shopId)
            ->whereBetween('credit_notes.issued_at', [$start, $end])
            ->leftJoin('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            ->leftJoin('customers', 'customers.id', '=', 'credit_notes.customer_id')
            ->select(
                'credit_notes.credit_note_number',
                'credit_notes.issued_at',
                'credit_notes.gst_rate',
                'credit_notes.subtotal as cn_subtotal',
                'credit_notes.gst as cn_gst',
                'credit_notes.total as cn_total',
                DB::raw('COALESCE(credit_notes.cgst_amount, ROUND(credit_notes.gst / 2.0, 2)) as cn_cgst'),
                DB::raw('COALESCE(credit_notes.sgst_amount, credit_notes.gst - ROUND(credit_notes.gst / 2.0, 2)) as cn_sgst'),
                DB::raw('COALESCE(credit_notes.igst_amount, 0) as cn_igst'),
                'invoices.invoice_number as original_invoice_number',
                'invoices.created_at as original_invoice_date',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(customers.first_name, '') || ' ' || COALESCE(customers.last_name, '')), ''), 'Walk-in') as customer_name")
            )
            ->orderBy('credit_notes.issued_at')
            ->get();

        $cnTaxableReversed = round((float) $creditNotes->sum('cn_subtotal'), 2);
        $cnGstReversed     = round((float) $creditNotes->sum('cn_gst'), 2);
        $cnTotalReversed   = round((float) $creditNotes->sum('cn_total'), 2);
        $cnCount           = $creditNotes->count();

        // Net liability = output tax collected − GST reversed via credit notes.
        // (Input Tax Credit, when modelled, subtracts further — out of scope here.)
        $netGstLiability = round($gstCollected - $cnGstReversed, 2);

        return new GstReportData(
            breakdown: $breakdown,
            taxableAmount: $taxableAmount,
            totalDiscount: $totalDiscount,
            gstCollected: $gstCollected,
            cgstCollected: $cgstCollected,
            sgstCollected: $sgstCollected,
            igstCollected: $igstCollected,
            totalSales: $totalSales,
            invoiceCount: $invoiceCount,
            creditNotes: $creditNotes,
            cnTaxableReversed: $cnTaxableReversed,
            cnGstReversed: $cnGstReversed,
            cnTotalReversed: $cnTotalReversed,
            cnCount: $cnCount,
            netGstLiability: $netGstLiability,
        );
    }
}

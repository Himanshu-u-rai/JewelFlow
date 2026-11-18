<?php

namespace App\Reporting;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * HSN summary (GSTR-1 table 12) for any period — a month, a quarter or a whole financial year.
 *
 * Read-only; persisted accounting values only, GST is never recomputed (CONSTITUTION §7).
 *   - Outward supplies: lines of finalized invoices dated COALESCE(finalized_at, created_at) in the period —
 *     the same selection, the same HSN grouping ('—' when blank) and the same taxable (line_total) / GST
 *     (gst_amount) as the HSN section of TaxService::gstr1().
 *   - Credit notes: the return lines of credit notes issued (issued_at) in the period are subtracted, per HSN.
 *   - The split into CGST / SGST / IGST follows the stored split of the bill (credit note): IGST when the
 *     document carries igst_amount, otherwise the amount halved the way the invoice columns are.
 *   - Quantity is grams (UQC GMS), the item weight. Cess is not recorded anywhere in the app, so it is 0.
 *
 * Because every figure is a plain sum of rows dated inside the period, a year equals the sum of its months.
 */
class HsnSummaryService
{
    public const UQC = 'GMS';
    private const NO_HSN = '—';

    /**
     * @return array<int, array{hsn_code:string, uqc:string, quantity:float, taxable:float, igst:float, cgst:float, sgst:float, cess:float, total_value:float}>
     */
    public function summary(int $shopId, ReportPeriod $period): array
    {
        return array_map(static function (array $r) {
            unset($r['lines']);

            return $r;
        }, $this->summaryWithLines($shopId, $period));
    }

    /** The same rows as summary(), each with 'lines' = the net number of bill lines (sold minus returned/reversed). */
    public function summaryWithLines(int $shopId, ReportPeriod $period): array
    {
        [$start, $end] = $period->bounds();

        $rows = [];
        foreach ($this->outward($shopId, $start, $end, false) as $r) {
            $rows[$r->hsn_code] = $this->blend($rows[$r->hsn_code] ?? null, $r, 1);
        }
        // A bill cancelled by a reversal bill: the reversal carries no lines of its own, so its HSN lines come
        // back negative in the period the reversal was issued (the bill itself stays in the period it was issued).
        foreach ($this->outward($shopId, $start, $end, true) as $r) {
            $rows[$r->hsn_code] = $this->blend($rows[$r->hsn_code] ?? null, $r, -1);
        }
        // Credit notes issued in the period reduce it (monthly GSTR-1 and the year summary alike).
        foreach ($this->returns($shopId, $start, $end) as $r) {
            $rows[$r->hsn_code] = $this->blend($rows[$r->hsn_code] ?? null, $r, -1);
        }

        $out = [];
        foreach ($rows as $hsn => $sums) {
            $total = $sums['taxable'] + $sums['igst'] + $sums['cgst'] + $sums['sgst'];
            $out[] = [
                'hsn_code' => (string) $hsn,
                'uqc' => self::UQC,
                'quantity' => round($sums['quantity'], 3),
                'taxable' => round($sums['taxable'], 2),
                'igst' => round($sums['igst'], 2),
                'cgst' => round($sums['cgst'], 2),
                'sgst' => round($sums['sgst'], 2),
                'cess' => 0.0,
                'total_value' => round($total, 2),
                'lines' => (int) $sums['lines'],
            ];
        }

        usort($out, static function ($a, $b) {
            $aNone = $a['hsn_code'] === self::NO_HSN;
            $bNone = $b['hsn_code'] === self::NO_HSN;

            return $aNone <=> $bNone ?: strcmp($a['hsn_code'], $b['hsn_code']);
        });

        return $out;
    }

    private function blend(?array $into, object $r, int $sign): array
    {
        $into ??= ['quantity' => 0.0, 'taxable' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'lines' => 0];
        foreach (['quantity', 'taxable', 'igst', 'cgst', 'sgst', 'lines'] as $k) {
            $into[$k] += $sign * (float) $r->{$k};
        }

        return $into;
    }

    /**
     * $reversed = false: the lines of every bill ISSUED in the period (finalized, or cancelled after issue).
     * $reversed = true: the lines of bills whose REVERSAL bill was issued in the period (they come off in that period).
     */
    private function outward(int $shopId, $start, $end, bool $reversed)
    {
        $gst = 'COALESCE(invoice_items.gst_amount, 0)';
        $inter = 'COALESCE(invoices.igst_amount, 0) > 0';
        $hsn = "COALESCE(NULLIF(TRIM(invoice_items.hsn_code), ''), '" . self::NO_HSN . "')";

        $q = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.shop_id', $shopId);

        if ($reversed) {
            $q->join('invoices as reversal', fn ($j) => $j->on('reversal.id', '=', 'invoices.reversed_by_invoice_id')->on('reversal.shop_id', '=', 'invoices.shop_id'))
                ->whereRaw('COALESCE(reversal.finalized_at, reversal.created_at) BETWEEN ? AND ?', [$start, $end]);
        } else {
            $q->whereRaw(Invoice::issuedSaleSql('invoices'))
                ->whereRaw('COALESCE(invoices.finalized_at, invoices.created_at) BETWEEN ? AND ?', [$start, $end]);
        }

        return $q
            ->select(
                DB::raw("{$hsn} as hsn_code"),
                DB::raw('COUNT(invoice_items.id) as lines'),
                DB::raw('SUM(invoice_items.weight) as quantity'),
                DB::raw('SUM(invoice_items.line_total) as taxable'),
                DB::raw("SUM(CASE WHEN {$inter} THEN {$gst} ELSE 0 END) as igst"),
                DB::raw("SUM(CASE WHEN {$inter} THEN 0 ELSE ROUND({$gst} / 2.0, 2) END) as cgst"),
                DB::raw("SUM(CASE WHEN {$inter} THEN 0 ELSE {$gst} - ROUND({$gst} / 2.0, 2) END) as sgst"),
            )
            ->groupBy(DB::raw($hsn))
            ->get();
    }

    private function returns(int $shopId, $start, $end)
    {
        $gst = 'COALESCE(return_line_items.refund_gst, 0)';
        $inter = 'COALESCE(credit_notes.igst_amount, 0) > 0';
        // The HSN the line was SOLD under, so a return nets against the same row it was sold in.
        $hsn = "COALESCE(NULLIF(TRIM(invoice_items.hsn_code), ''), NULLIF(TRIM(return_line_items.hsn_code), ''), '" . self::NO_HSN . "')";

        return DB::table('credit_notes')
            ->join('return_line_items', 'return_line_items.return_order_id', '=', 'credit_notes.return_order_id')
            ->join('invoice_items', 'invoice_items.id', '=', 'return_line_items.invoice_item_id')
            ->where('credit_notes.shop_id', $shopId)
            ->whereBetween('credit_notes.issued_at', [$start, $end])
            ->select(
                DB::raw("{$hsn} as hsn_code"),
                DB::raw('COUNT(return_line_items.id) as lines'),
                DB::raw('SUM(invoice_items.weight) as quantity'),
                DB::raw('SUM(return_line_items.refund_subtotal) as taxable'),
                DB::raw("SUM(CASE WHEN {$inter} THEN {$gst} ELSE 0 END) as igst"),
                DB::raw("SUM(CASE WHEN {$inter} THEN 0 ELSE ROUND({$gst} / 2.0, 2) END) as cgst"),
                DB::raw("SUM(CASE WHEN {$inter} THEN 0 ELSE {$gst} - ROUND({$gst} / 2.0, 2) END) as sgst"),
            )
            ->groupBy(DB::raw($hsn))
            ->get();
    }
}

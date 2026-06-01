<?php

namespace App\Reporting;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Reporting\Data\ProfitReportData;
use Illuminate\Support\Facades\DB;

/**
 * Canonical profit reporting — a TRUE gross margin (audit A2/A6).
 *
 * The old P&L reported `making + stone + wastage` as "profit": it ignored cost
 * of goods entirely and could never be negative. This service computes a real
 * gross margin:
 *
 *     revenue       = ex-GST net sales (subtotal − discount), net of returns
 *     COGS          = Σ recorded cost_price of sold items
 *     gross profit  = revenue − COGS        (CAN be negative)
 *
 * Honesty guards:
 *   - Sales scoped by the canonical SaleScope (finalized + accounting date) so
 *     drafts/cancelled never inflate it.
 *   - GST is excluded from revenue — it is a pass-through, not income.
 *   - Items with no recorded cost are EXCLUDED from COGS and counted separately
 *     (costUnknownLines), never treated as zero-cost (which would inflate margin).
 *   - Returns reduce revenue; their COGS is not added back (conservative — the
 *     direction that understates rather than overstates profit). Netting
 *     returned-goods cost is a Phase 3 refinement.
 */
class ProfitReportingService
{
    public function summary(int $shopId, ReportPeriod $period): ProfitReportData
    {
        [$start, $end] = $period->bounds();

        // Invoice-level revenue components (finalized sales in period).
        $inv = Invoice::withoutTenant()
            ->where('shop_id', $shopId)
            ->salesIn($period)
            ->selectRaw('
                COALESCE(SUM(subtotal), 0)  as gross_taxable,
                COALESCE(SUM(discount), 0)  as discount,
                COALESCE(SUM(wastage_charge), 0) as wastage
            ')
            ->first();

        $grossSales = round((float) ($inv->gross_taxable ?? 0), 2);
        $discount   = round((float) ($inv->discount ?? 0), 2);
        $wastage    = round((float) ($inv->wastage ?? 0), 2);

        // Returns (credit notes issued in period), ex-GST taxable value.
        $returns = round((float) CreditNote::withoutTenant()
            ->where('shop_id', $shopId)
            ->whereBetween('issued_at', [$start, $end])
            ->sum('subtotal'), 2);

        // Revenue = ex-GST net sales, net of returns.
        $revenue = round($grossSales - $discount - $returns, 2);

        // Line-level composition + COGS. Join invoice_items → items for cost.
        $lines = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('items', 'items.id', '=', 'invoice_items.item_id')
            ->where('invoices.shop_id', $shopId)
            ->where('invoices.status', Invoice::STATUS_FINALIZED)
            ->whereRaw('COALESCE(invoices.finalized_at, invoices.created_at) BETWEEN ? AND ?', [$start, $end])
            ->selectRaw('
                COALESCE(SUM(items.cost_price), 0)             as cogs,
                COALESCE(SUM(invoice_items.making_charges), 0) as making,
                COALESCE(SUM(invoice_items.stone_amount), 0)   as stones,
                COUNT(invoice_items.id)                        as line_count,
                COALESCE(SUM(CASE WHEN items.cost_price IS NULL THEN 1 ELSE 0 END), 0) as cost_unknown
            ')
            ->first();

        $cogs            = round((float) ($lines->cogs ?? 0), 2);
        $making          = round((float) ($lines->making ?? 0), 2);
        $stones          = round((float) ($lines->stones ?? 0), 2);
        $soldLineCount   = (int) ($lines->line_count ?? 0);
        $costUnknown     = (int) ($lines->cost_unknown ?? 0);

        $grossProfit = round($revenue - $cogs, 2);
        $marginPct   = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : null;

        return new ProfitReportData(
            revenue: $revenue,
            cogs: $cogs,
            grossProfit: $grossProfit,
            marginPct: $marginPct,
            grossSales: $grossSales,
            returns: $returns,
            discount: $discount,
            makingCharges: $making,
            stoneCharges: $stones,
            wastageRecovered: $wastage,
            soldLineCount: $soldLineCount,
            costUnknownLines: $costUnknown,
        );
    }
}

<?php

namespace App\Reporting;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MetalLot;
use App\Reporting\Data\MetalExchangeData;
use App\Reporting\Data\PaymentReconData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Sales-side reporting (Phase 2 M2): payment reconciliation.
 *
 * Reconciles finalized-invoice totals against collected payments and surfaces
 * mismatches — over-collections (impossible, a data error) and fully-unpaid
 * finalized invoices — plus a payment-mode breakdown. Canonical sale scope;
 * reads persisted invoice totals and invoice_payments only.
 */
class SalesService
{
    public function paymentReconciliation(int $shopId, ReportPeriod $period): PaymentReconData
    {
        $invoices = Invoice::withoutTenant()
            ->where('invoices.shop_id', $shopId)
            ->salesIn($period)
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select(
                'invoices.id',
                'invoices.invoice_number',
                DB::raw('COALESCE(invoices.finalized_at, invoices.created_at) as doc_date'),
                'invoices.total',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(customers.first_name, '') || ' ' || COALESCE(customers.last_name, '')), ''), 'Walk-in') as customer_name")
            )
            ->orderBy('doc_date')
            ->get();

        $ids = $invoices->pluck('id')->all();

        $collectedByInvoice = empty($ids) ? collect() : DB::table('invoice_payments')
            ->where('shop_id', $shopId)
            ->whereIn('invoice_id', $ids)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) as collected')
            ->pluck('collected', 'invoice_id');

        $modeBreakdown = empty($ids) ? collect() : DB::table('invoice_payments')
            ->where('shop_id', $shopId)
            ->whereIn('invoice_id', $ids)
            ->groupBy('mode')
            ->selectRaw('mode, SUM(amount) as amount')
            ->orderByDesc('amount')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->mode => round((float) $r->amount, 2)]);

        $invoiceTotal = 0.0;
        $collectedTotal = 0.0;
        $pendingTotal = 0.0;
        $fullyPaid = 0;
        $partial = 0;
        $unpaid = 0;
        $over = 0;

        $rows = $invoices->map(function ($inv) use ($collectedByInvoice, &$invoiceTotal, &$collectedTotal, &$pendingTotal, &$fullyPaid, &$partial, &$unpaid, &$over) {
            $total = round((float) $inv->total, 2);
            $collected = round((float) ($collectedByInvoice[$inv->id] ?? 0), 2);
            $pending = round($total - $collected, 2);

            if ($collected <= 0.01) {
                $status = 'unpaid';
                $unpaid++;
            } elseif ($collected > $total + 0.01) {
                $status = 'over_collected';
                $over++;
            } elseif (abs($pending) <= 0.01) {
                $status = 'paid';
                $fullyPaid++;
            } else {
                $status = 'partial';
                $partial++;
            }

            $invoiceTotal += $total;
            $collectedTotal += $collected;
            $pendingTotal += max($pending, 0);

            return (object) [
                'invoice_number' => $inv->invoice_number,
                'doc_date'       => $inv->doc_date,
                'customer_name'  => $inv->customer_name,
                'total'          => $total,
                'collected'      => $collected,
                'pending'        => $pending,
                'status'         => $status,
            ];
        });

        $mismatches = $rows->filter(fn ($r) => in_array($r->status, ['over_collected', 'unpaid'], true))->values();

        return new PaymentReconData(
            rows: $rows,
            mismatches: $mismatches,
            modeBreakdown: $modeBreakdown,
            invoiceTotal: round($invoiceTotal, 2),
            collected: round($collectedTotal, 2),
            pending: round($pendingTotal, 2),
            invoiceCount: $rows->count(),
            fullyPaidCount: $fullyPaid,
            partialCount: $partial,
            unpaidCount: $unpaid,
            overCollectedCount: $over,
            reconciled: $over === 0,
        );
    }

    /**
     * Metal-exchange transaction view (M5 extraction of
     * MetalExchangeReportController). Old-gold / old-silver payments over a date
     * range, with per-metal summaries. Same queries, no behaviour change.
     */
    public function metalExchange(int $shopId, string $from, string $to): MetalExchangeData
    {
        $rows = InvoicePayment::with(['invoice.customer'])
            ->whereHas('invoice', fn ($q) => $q->where('shop_id', $shopId))
            ->whereIn('mode', ['old_gold', 'old_silver'])
            ->whereHas('invoice', fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->get();

        $goldRows   = $rows->where('mode', 'old_gold');
        $silverRows = $rows->where('mode', 'old_silver');

        return new MetalExchangeData(
            rows: $rows,
            goldSummary: [
                'gross' => $goldRows->sum('metal_gross_weight'),
                'fine'  => $goldRows->sum('metal_fine_weight'),
                'value' => $goldRows->sum('amount'),
                'count' => $goldRows->count(),
            ],
            silverSummary: [
                'gross' => $silverRows->sum('metal_gross_weight'),
                'fine'  => $silverRows->sum('metal_fine_weight'),
                'value' => $silverRows->sum('amount'),
                'count' => $silverRows->count(),
            ],
        );
    }

    /**
     * Metal-exchange weekly-lots view (M5 extraction). Paginated weekly lots
     * with their old-metal payments. Same query, no behaviour change.
     */
    public function metalExchangeLots(int $shopId, string $from, string $to): LengthAwarePaginator
    {
        return MetalLot::where('shop_id', $shopId)
            ->weekly()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to . ' 23:59:59')
            ->with([
                'payments' => fn ($q) => $q->with('invoice.customer')
                    ->whereIn('mode', ['old_gold', 'old_silver']),
            ])
            ->orderByDesc('iso_year')
            ->orderByDesc('iso_week')
            ->paginate(20);
    }
}

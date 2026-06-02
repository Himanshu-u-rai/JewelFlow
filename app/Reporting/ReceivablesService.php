<?php

namespace App\Reporting;

use App\Models\Invoice;
use App\Reporting\Data\DuesAgingData;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receivables & liability reporting (Phase 2 M3).
 *
 * #8 Customer dues aging — outstanding on finalized invoices, bucketed by age.
 * Uses the canonical sale scope (finalized) and persisted invoice totals +
 * invoice_payments only; no parallel sales definition, no controller-level
 * aggregation. This is a point-in-time snapshot, not a period report.
 */
class ReceivablesService
{
    public function duesAging(int $shopId, ?Carbon $asOf = null): DuesAgingData
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->endOfDay();

        $invoices = Invoice::withoutTenant()
            ->where('invoices.shop_id', $shopId)
            ->finalizedSale()
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select(
                'invoices.id',
                'invoices.customer_id',
                DB::raw('COALESCE(invoices.finalized_at, invoices.created_at) as doc_date'),
                'invoices.total',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(customers.first_name, '') || ' ' || COALESCE(customers.last_name, '')), ''), 'Walk-in') as customer_name"),
                'customers.mobile as customer_mobile'
            )
            ->get();

        $ids = $invoices->pluck('id')->all();

        $collectedByInvoice = empty($ids) ? collect() : DB::table('invoice_payments')
            ->where('shop_id', $shopId)
            ->whereIn('invoice_id', $ids)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) as collected')
            ->pluck('collected', 'invoice_id');

        // Per-customer accumulator keyed by customer_id (null → walk-in bucket).
        $byCustomer = [];
        $bC = 0.0; $b3160 = 0.0; $b6190 = 0.0; $b90 = 0.0; $invCount = 0;

        foreach ($invoices as $inv) {
            $total = round((float) $inv->total, 2);
            $collected = round((float) ($collectedByInvoice[$inv->id] ?? 0), 2);
            $outstanding = round($total - $collected, 2);
            if ($outstanding <= 0.01) {
                continue; // fully paid / over-collected / reversal — not a due
            }

            $ageDays = Carbon::parse($inv->doc_date)->startOfDay()->diffInDays($asOf, false);
            $ageDays = max(0, (int) $ageDays);

            if ($ageDays <= 30)       { $bucket = 'current'; $bC += $outstanding; }
            elseif ($ageDays <= 60)   { $bucket = 'd3160';   $b3160 += $outstanding; }
            elseif ($ageDays <= 90)   { $bucket = 'd6190';   $b6190 += $outstanding; }
            else                      { $bucket = 'd90plus'; $b90 += $outstanding; }

            $key = $inv->customer_id ?? 'walkin';
            if (!isset($byCustomer[$key])) {
                $byCustomer[$key] = [
                    'customer_name' => $inv->customer_name,
                    'mobile'        => $inv->customer_mobile,
                    'invoice_count' => 0,
                    'current'       => 0.0,
                    'd3160'         => 0.0,
                    'd6190'         => 0.0,
                    'd90plus'       => 0.0,
                    'total'         => 0.0,
                ];
            }
            $byCustomer[$key]['invoice_count']++;
            $byCustomer[$key][$bucket] += $outstanding;
            $byCustomer[$key]['total'] += $outstanding;
            $invCount++;
        }

        $rows = collect($byCustomer)
            ->map(function ($c) {
                $c['current']  = round($c['current'], 2);
                $c['d3160']    = round($c['d3160'], 2);
                $c['d6190']    = round($c['d6190'], 2);
                $c['d90plus']  = round($c['d90plus'], 2);
                $c['total']    = round($c['total'], 2);
                return (object) $c;
            })
            ->sortByDesc('total')
            ->values();

        return new DuesAgingData(
            rows: $rows,
            bucketCurrent: round($bC, 2),
            bucket3160: round($b3160, 2),
            bucket6190: round($b6190, 2),
            bucket90plus: round($b90, 2),
            totalOutstanding: round($bC + $b3160 + $b6190 + $b90, 2),
            customerCount: $rows->count(),
            invoiceCount: $invCount,
            asOf: $asOf->format('Y-m-d'),
        );
    }
}

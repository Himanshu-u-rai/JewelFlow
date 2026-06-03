<?php

namespace App\Reporting;

use App\Models\Invoice;
use App\Reporting\Data\OperatorPerfData;
use Illuminate\Support\Facades\DB;

/**
 * Operator / audit reporting (Phase 2 M4).
 *
 * #16 Operator performance — sales by the operator who created the invoice,
 * returns by the operator who issued the credit note. Canonical sale scope;
 * legacy invoices with no operator are bucketed as "Unattributed" so the sales
 * total reconciles to the GST total sales. Reads persisted tables only.
 */
class AuditService
{
    public function operatorPerformance(int $shopId, ReportPeriod $period): OperatorPerfData
    {
        // Sales side — finalized invoices in the accounting period, by operator.
        $sales = Invoice::withoutTenant()
            ->where('invoices.shop_id', $shopId)
            ->salesIn($period)
            ->groupBy('invoices.user_id')
            ->selectRaw('invoices.user_id as uid, COUNT(*) as cnt, COALESCE(SUM(total), 0) as sales, COALESCE(SUM(discount), 0) as disc')
            ->get()
            ->keyBy('uid');

        // Returns side — credit notes issued in the period, by issuing operator.
        $returns = DB::table('credit_notes')
            ->where('shop_id', $shopId)
            ->whereBetween('issued_at', [$period->start(), $period->end()])
            ->groupBy('issued_by_user_id')
            ->selectRaw('issued_by_user_id as uid, COUNT(*) as cnt, COALESCE(SUM(total), 0) as val')
            ->get()
            ->keyBy('uid');

        $uids = $sales->keys()->merge($returns->keys())->unique()->values();

        $names = DB::table('users')
            ->whereIn('id', $uids->filter()->all())
            ->pluck('name', 'id');

        $totalSales = 0.0; $totalDiscount = 0.0; $totalReturns = 0.0;

        $rows = $uids->map(function ($uid) use ($sales, $returns, $names, &$totalSales, &$totalDiscount, &$totalReturns) {
            $s = $sales->get($uid);
            $r = $returns->get($uid);

            $sellSales = round((float) ($s->sales ?? 0), 2);
            $sellDisc = round((float) ($s->disc ?? 0), 2);
            $retVal = round((float) ($r->val ?? 0), 2);

            $totalSales += $sellSales;
            $totalDiscount += $sellDisc;
            $totalReturns += $retVal;

            return (object) [
                'operator_name'  => $uid ? ($names[$uid] ?? ('User #' . $uid)) : 'Unattributed',
                'invoice_count'  => (int) ($s->cnt ?? 0),
                'total_sales'    => $sellSales,
                'total_discount' => $sellDisc,
                'returns_count'  => (int) ($r->cnt ?? 0),
                'returns_value'  => $retVal,
                'net_sales'      => round($sellSales - $retVal, 2),
            ];
        })->sortByDesc('total_sales')->values();

        return new OperatorPerfData(
            rows: $rows,
            totalSales: round($totalSales, 2),
            totalDiscount: round($totalDiscount, 2),
            totalReturnsValue: round($totalReturns, 2),
            operatorCount: $rows->count(),
            periodLabel: $period->label(),
        );
    }
}

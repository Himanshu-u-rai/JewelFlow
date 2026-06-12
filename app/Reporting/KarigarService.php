<?php

namespace App\Reporting;

use App\Reporting\Data\KarigarSettlementData;
use Illuminate\Support\Facades\DB;

/**
 * Karigar reporting (Phase 2 M4): settlement / accountability.
 *
 * Per-karigar grams (issued vs received vs wastage → outstanding with karigar)
 * from OPEN job orders, and money (invoiced vs paid → outstanding payable) from
 * karigar_invoices. Reads persisted tables only; the gram balance mirrors the
 * karigar:reconcile command's formula.
 */
class KarigarService
{
    public function settlement(int $shopId): KarigarSettlementData
    {
        // Gram side — open job orders (issued / partial_return).
        $grams = DB::table('job_orders as jo')
            ->where('jo.shop_id', $shopId)
            ->whereIn('jo.status', ['issued', 'partial_return'])
            ->groupBy('jo.karigar_id')
            ->select(
                'jo.karigar_id',
                DB::raw('COUNT(*) as open_jobs'),
                DB::raw('COALESCE(SUM(jo.issued_fine_weight), 0) as issued_fine'),
                DB::raw('COALESCE(SUM(jo.returned_fine_weight), 0) as received_fine'),
                DB::raw('COALESCE(SUM(jo.actual_wastage_fine), 0) as wastage_fine'),
                DB::raw('COALESCE(SUM(jo.issued_fine_weight - COALESCE(jo.returned_fine_weight,0) - COALESCE(jo.leftover_returned_fine_weight,0) - COALESCE(jo.actual_wastage_fine,0)), 0) as outstanding_fine')
            )
            ->get()
            ->keyBy('karigar_id');

        // Money side — all karigar invoices (unpaid balance is the payable).
        $money = DB::table('karigar_invoices')
            ->where('shop_id', $shopId)
            ->groupBy('karigar_id')
            ->select(
                'karigar_id',
                DB::raw('COALESCE(SUM(total_after_tax), 0) as invoiced'),
                DB::raw('COALESCE(SUM(amount_paid), 0) as paid')
            )
            ->get()
            ->keyBy('karigar_id');

        $karigarIds = $grams->keys()->merge($money->keys())->unique()->filter()->values();

        $names = DB::table('karigars')->where('shop_id', $shopId)
            ->whereIn('id', $karigarIds->all())
            ->pluck('name', 'id');

        $totalOutFine = 0.0; $totalInvoiced = 0.0; $totalPaid = 0.0; $totalPayable = 0.0;

        $rows = $karigarIds->map(function ($id) use ($grams, $money, $names, &$totalOutFine, &$totalInvoiced, &$totalPaid, &$totalPayable) {
            $g = $grams->get($id);
            $m = $money->get($id);

            $issued = round((float) ($g->issued_fine ?? 0), 4);
            $received = round((float) ($g->received_fine ?? 0), 4);
            $wastage = round((float) ($g->wastage_fine ?? 0), 4);
            $outFine = round((float) ($g->outstanding_fine ?? 0), 4);
            $invoiced = round((float) ($m->invoiced ?? 0), 2);
            $paid = round((float) ($m->paid ?? 0), 2);
            $payable = round($invoiced - $paid, 2);

            $totalOutFine += $outFine;
            $totalInvoiced += $invoiced;
            $totalPaid += $paid;
            $totalPayable += $payable;

            return (object) [
                'karigar_name'        => $names[$id] ?? ('Karigar #' . $id),
                'open_jobs'           => (int) ($g->open_jobs ?? 0),
                'issued_fine'         => $issued,
                'received_fine'       => $received,
                'wastage_fine'        => $wastage,
                'outstanding_fine'    => $outFine,
                'invoiced'            => $invoiced,
                'paid'                => $paid,
                'outstanding_payable' => $payable,
            ];
        })->sortByDesc('outstanding_payable')->values();

        return new KarigarSettlementData(
            rows: $rows,
            totalOutstandingFine: round($totalOutFine, 4),
            totalInvoiced: round($totalInvoiced, 2),
            totalPaid: round($totalPaid, 2),
            totalOutstandingPayable: round($totalPayable, 2),
            karigarCount: $rows->count(),
        );
    }

    /**
     * #15 Metal shrinkage / loss variance over COMPLETED job orders in the
     * period. Reconciles the gram trail per job:
     *   unaccounted = issued − items_returned − leftover_returned − declared_wastage
     * Declared wastage is legitimate making loss; unaccounted is the residual the
     * receive path could not explain (should be ~0). Reads completed job_orders
     * only — never touches the receive path.
     */
    public function shrinkage(int $shopId, ReportPeriod $period): \App\Reporting\Data\ShrinkageData
    {
        $base = DB::table('job_orders as jo')
            ->where('jo.shop_id', $shopId)
            ->where('jo.status', 'completed')
            ->whereBetween('jo.completed_at', [$period->start(), $period->end()]);

        $issuedExpr    = 'COALESCE(jo.issued_fine_weight, 0)';
        $returnedExpr  = 'COALESCE(jo.returned_fine_weight, 0)';
        $leftoverExpr  = 'COALESCE(jo.leftover_returned_fine_weight, 0)';
        $wastageExpr   = 'COALESCE(jo.actual_wastage_fine, 0)';
        // Retained metal is held BY the karigar (a real asset), not shrinkage —
        // subtract it so it never shows up as "unaccounted" loss.
        $retainedExpr  = 'COALESCE(jo.retained_returned_fine_weight, 0)';
        $unaccExpr     = "($issuedExpr - $returnedExpr - $leftoverExpr - $wastageExpr - $retainedExpr)";

        // Per-karigar aggregation.
        $perKarigar = (clone $base)
            ->groupBy('jo.karigar_id')
            ->select(
                'jo.karigar_id',
                DB::raw('COUNT(*) as job_count'),
                DB::raw("SUM($issuedExpr) as issued_fine"),
                DB::raw("SUM($returnedExpr) as returned_fine"),
                DB::raw("SUM($leftoverExpr) as leftover_fine"),
                DB::raw("SUM($wastageExpr) as wastage_fine"),
                DB::raw("SUM($retainedExpr) as retained_fine"),
                DB::raw("SUM($unaccExpr) as unaccounted_fine")
            )
            ->get();

        $names = DB::table('karigars')->where('shop_id', $shopId)
            ->whereIn('id', $perKarigar->pluck('karigar_id')->filter()->all())
            ->pluck('name', 'id');

        $rows = $perKarigar->map(function ($r) use ($names) {
            $issued = round((float) $r->issued_fine, 4);
            $wastage = round((float) $r->wastage_fine, 4);
            return (object) [
                'karigar_name'     => $names[$r->karigar_id] ?? ('Karigar #' . $r->karigar_id),
                'job_count'        => (int) $r->job_count,
                'issued_fine'      => $issued,
                'returned_fine'    => round((float) $r->returned_fine, 4),
                'leftover_fine'    => round((float) $r->leftover_fine, 4),
                'wastage_fine'     => $wastage,
                'retained_fine'    => round((float) $r->retained_fine, 4),
                'wastage_pct'      => $issued > 0 ? round($wastage / $issued * 100, 2) : 0.0,
                'unaccounted_fine' => round((float) $r->unaccounted_fine, 4),
            ];
        })->sortByDesc('unaccounted_fine')->values();

        // Per-metal aggregation.
        $byMetal = (clone $base)
            ->groupBy('jo.metal_type')
            ->select(
                'jo.metal_type',
                DB::raw("SUM($issuedExpr) as issued_fine"),
                DB::raw("SUM($wastageExpr) as wastage_fine"),
                DB::raw("SUM($unaccExpr) as unaccounted_fine")
            )
            ->get()
            ->map(function ($r) {
                $issued = round((float) $r->issued_fine, 4);
                $wastage = round((float) $r->wastage_fine, 4);
                return (object) [
                    'metal_type'       => $r->metal_type ?? 'unknown',
                    'issued_fine'      => $issued,
                    'wastage_fine'     => $wastage,
                    'wastage_pct'      => $issued > 0 ? round($wastage / $issued * 100, 2) : 0.0,
                    'unaccounted_fine' => round((float) $r->unaccounted_fine, 4),
                ];
            })->sortByDesc('issued_fine')->values();

        $totalIssued    = round((float) $rows->sum('issued_fine'), 4);
        $totalReturned  = round((float) $rows->sum('returned_fine'), 4);
        $totalLeftover  = round((float) $rows->sum('leftover_fine'), 4);
        $totalWastage   = round((float) $rows->sum('wastage_fine'), 4);
        $totalRetained  = round((float) $rows->sum('retained_fine'), 4);
        $totalUnacc     = round((float) $rows->sum('unaccounted_fine'), 4);

        return new \App\Reporting\Data\ShrinkageData(
            rows: $rows,
            byMetal: $byMetal,
            totalIssued: $totalIssued,
            totalReturned: $totalReturned,
            totalLeftover: $totalLeftover,
            totalWastage: $totalWastage,
            totalRetained: $totalRetained,
            totalUnaccounted: $totalUnacc,
            wastagePct: $totalIssued > 0 ? round($totalWastage / $totalIssued * 100, 2) : 0.0,
            jobCount: (int) $rows->sum('job_count'),
            periodLabel: $period->label(),
        );
    }
}

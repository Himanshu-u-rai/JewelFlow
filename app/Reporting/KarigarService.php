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
}

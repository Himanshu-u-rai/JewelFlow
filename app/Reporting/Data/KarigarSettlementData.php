<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Karigar settlement report (Phase 2 M4, report #13).
 *
 * Per-karigar accountability: the GRAM side (issued vs received fine weight,
 * wastage, outstanding still with the karigar — from open job orders) and the
 * MONEY side (total karigar invoiced vs paid → outstanding payable). Surfaces
 * the same gram balance the karigar:reconcile command checks, as a report.
 * Reads persisted job_orders + karigar_invoices only.
 */
final class KarigarSettlementData
{
    public function __construct(
        public readonly Collection $rows,   // per-karigar: karigar_name, open_jobs, issued_fine, received_fine, wastage_fine, outstanding_fine, invoiced, paid, outstanding_payable
        public readonly float $totalOutstandingFine,    // grams still with karigars
        public readonly float $totalInvoiced,
        public readonly float $totalPaid,
        public readonly float $totalOutstandingPayable,  // money still owed to karigars
        public readonly int $karigarCount,
    ) {}
}

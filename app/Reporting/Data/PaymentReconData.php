<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Payment reconciliation for a period (Phase 2 M2).
 *
 * Surfaces mismatches, not just totals (nxr3): per-invoice collected vs total,
 * over-collections (a data error), and fully-unpaid finalized invoices, plus a
 * payment-mode breakdown. All scoped by the canonical sale scope.
 */
final class PaymentReconData
{
    public function __construct(
        public readonly Collection $rows,          // per-invoice: number,date,customer,total,collected,pending,status
        public readonly Collection $mismatches,    // subset: status === over_collected | unpaid
        public readonly Collection $modeBreakdown, // [mode => amount]
        public readonly float $invoiceTotal,
        public readonly float $collected,
        public readonly float $pending,
        public readonly int $invoiceCount,
        public readonly int $fullyPaidCount,
        public readonly int $partialCount,
        public readonly int $unpaidCount,
        public readonly int $overCollectedCount,
        public readonly bool $reconciled,          // true when no over-collections
    ) {}
}

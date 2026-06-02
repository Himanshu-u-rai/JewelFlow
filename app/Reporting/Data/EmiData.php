<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Pending EMI / installment visibility (Phase 2 M3, report #9).
 *
 * Point-in-time view (as of `asOf`) of ACTIVE installment plans and their
 * outstanding balance (the maintained `remaining_amount`), with overdue and
 * upcoming-due surfacing. Completed/defaulted plans are excluded — they carry
 * no live receivable. Reads persisted installment_plans only.
 */
final class EmiData
{
    public function __construct(
        public readonly Collection $rows,   // per-plan: customer_name, invoice_number, total_payable, paid, remaining, emis_paid, total_emis, next_due_date, overdue, days_overdue
        public readonly float $totalOutstanding,
        public readonly float $overdueAmount,
        public readonly float $upcomingAmount,   // due within 7 days of asOf (not yet overdue)
        public readonly int $planCount,
        public readonly int $overdueCount,
        public readonly int $upcomingCount,
        public readonly string $asOf,            // Y-m-d
    ) {}
}

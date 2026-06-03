<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Scheme liability exposure (Phase 2 M3, report #10).
 *
 * What the shop owes on gold-savings schemes: the current scheme-ledger balance
 * (contributions − redemptions + accrued bonus) of NON-terminal enrollments
 * (active + matured). Cancelled/redeemed enrollments carry no liability and are
 * excluded. Reads persisted scheme_enrollments + scheme_ledger_entries only.
 */
final class SchemeLiabilityData
{
    public function __construct(
        public readonly Collection $rows,   // per-enrollment: customer_name, scheme_name, status, total_paid, bonus_accrued, current_balance, maturity_date
        public readonly float $totalLiability,    // Σ current ledger balance
        public readonly float $totalContributions, // Σ total_paid
        public readonly float $bonusAccrued,       // Σ bonus where is_bonus_accrued
        public readonly int $enrollmentCount,
        public readonly int $maturedCount,
    ) {}
}

<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Cash flow over a period (Phase 3). The canonical opening / in / out / closing
 * aggregation over cash_transactions, plus the per-entry ledger with a running
 * balance. Lives in the reporting service layer (LedgerService) — NOT in the
 * report layer — so the report wraps it without re-deriving balances.
 *
 * Identity: closing == opening + cashIn − cashOut, and
 * closing == Σ(in − out) over all cash_transactions up to the period end.
 */
final class CashFlowData
{
    public function __construct(
        public readonly float $opening,
        public readonly float $cashIn,
        public readonly float $cashOut,
        public readonly float $closing,
        public readonly Collection $rows,   // {occurred_at, type, source, payment_mode, description, operator, amount, running_balance}
        public readonly int $count,
    ) {
    }
}

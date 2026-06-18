<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Per-payment-mode cash flow over a period. Same opening + in − out = closing
 * identity as CashFlowData, but split by money mode (cash / upi / bank / card /
 * wallet / other / any shop-configured type found in the data).
 *
 * Computed from immutable cash_transactions — never stored. Historical NULL
 * payment_mode is treated as 'cash' defensively at read time via COALESCE; the
 * stored NULL stays truthful. Old gold/silver never appears here because those
 * tenders never create a cash_transactions row (excluded at write time).
 *
 * Identity per mode: closing == opening + moneyIn − moneyOut.
 * The `cash` row is the physical drawer figure. `total*` are the cross-mode sums.
 */
final class PerModeCashFlowData
{
    /**
     * @param  Collection  $modes  each: {mode, opening, moneyIn, moneyOut, closing}
     */
    public function __construct(
        public readonly Collection $modes,
        public readonly float $totalOpening,
        public readonly float $totalIn,
        public readonly float $totalOut,
        public readonly float $totalClosing,
    ) {
    }

    /** The cash (drawer) row, or a zeroed row if no cash movements exist. */
    public function cash(): object
    {
        return $this->modes->firstWhere('mode', 'cash') ?? (object) [
            'mode' => 'cash', 'opening' => 0.0, 'moneyIn' => 0.0, 'moneyOut' => 0.0, 'closing' => 0.0,
        ];
    }
}

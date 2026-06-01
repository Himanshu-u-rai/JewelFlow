<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Credit/Debit-note register for a period (GST CN section + audit).
 *
 * Each row references its original invoice and carries a cn_type:
 *   full_cancellation — credit note with no return_order_id (whole invoice undone)
 *   partial_return    — credit note tied to a return order
 */
final class CnRegisterData
{
    public function __construct(
        public readonly Collection $rows,
        public readonly int $count,
        public readonly float $totalTaxable,
        public readonly float $totalGst,
        public readonly float $totalValue,
    ) {}
}

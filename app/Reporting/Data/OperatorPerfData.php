<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Operator performance (Phase 2 M4, report #16).
 *
 * Sales attributed to the operator who created the invoice, plus returns
 * attributed to the operator who issued the credit note, over a period.
 * Invoices with no recorded operator (legacy rows) are bucketed as
 * "Unattributed" so the sales total always reconciles to the canonical GST
 * total sales. Reads persisted invoices + credit_notes only.
 */
final class OperatorPerfData
{
    public function __construct(
        public readonly Collection $rows,   // per-operator: operator_name, invoice_count, total_sales, total_discount, returns_count, returns_value, net_sales
        public readonly float $totalSales,
        public readonly float $totalDiscount,
        public readonly float $totalReturnsValue,
        public readonly int $operatorCount,
        public readonly string $periodLabel,
    ) {}
}

<?php

namespace App\Reporting\Data;

/**
 * Immutable result of ProfitReportingService::summary().
 *
 * Honest gross margin (audit A2): revenue is ex-GST net sales (net of returns),
 * COGS is the recorded cost_price of sold items, and gross profit = revenue −
 * COGS. It CAN be negative. Items with no recorded cost are excluded from COGS
 * and surfaced via costUnknownLines so the margin is never silently inflated.
 */
final class ProfitReportData
{
    public function __construct(
        public readonly float $revenue,          // ex-GST net sales, net of credit notes
        public readonly float $cogs,             // Σ cost_price of sold items (known cost only)
        public readonly float $grossProfit,      // revenue − cogs (may be negative)
        public readonly ?float $marginPct,       // null when revenue == 0
        // Composition / transparency
        public readonly float $grossSales,       // ex-GST sales before returns
        public readonly float $returns,          // ex-GST returns (credit-note taxable)
        public readonly float $discount,
        public readonly float $makingCharges,
        public readonly float $stoneCharges,
        public readonly float $wastageRecovered,
        public readonly int $soldLineCount,
        public readonly int $costUnknownLines,   // sold lines with no recorded cost_price
    ) {}
}

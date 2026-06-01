<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Immutable result of GstReportingService::summary().
 *
 * Carries the output-tax breakdown, the credit-note (return) reversals, and
 * the net liability — so the on-screen report and any GSTR export read the
 * exact same numbers (audit A1/A7).
 */
final class GstReportData
{
    public function __construct(
        // Output side (finalized sales)
        public readonly Collection $breakdown,      // per-rate rows: gst_rate, taxable, discount, gst, cgst, sgst, igst, total, count
        public readonly float $taxableAmount,
        public readonly float $totalDiscount,
        public readonly float $gstCollected,
        public readonly float $cgstCollected,
        public readonly float $sgstCollected,
        public readonly float $igstCollected,
        public readonly float $totalSales,
        public readonly int $invoiceCount,
        // Credit-note side (returns)
        public readonly Collection $creditNotes,    // detail rows for display
        public readonly float $cnTaxableReversed,
        public readonly float $cnGstReversed,
        public readonly float $cnTotalReversed,
        public readonly int $cnCount,
        // Net
        public readonly float $netGstLiability,
    ) {}
}

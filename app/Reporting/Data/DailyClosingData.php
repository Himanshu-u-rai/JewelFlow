<?php

namespace App\Reporting\Data;

/**
 * Daily closing (Phase 3) — the combined canonical totals for one date:
 * finalized sales + GST (from GstReportingService) and cash movement (from
 * LedgerService::cashFlow). This is an AGGREGATOR over the existing canonical
 * services — it never re-derives sales, GST, or cash. So by construction:
 *   totalSales == GST report totalSales == Sales Register total for the date
 *   gstCollected/cgst/sgst/igst == GST report
 *   cash* == Cash Flow for the date
 */
final class DailyClosingData
{
    public function __construct(
        public readonly string $date,
        // Sales + GST (canonical GstReportingService for the day).
        public readonly float $totalSales,
        public readonly float $discount,
        public readonly float $taxable,
        public readonly float $cgst,
        public readonly float $sgst,
        public readonly float $igst,
        public readonly float $gstCollected,
        public readonly int $invoiceCount,
        // Cash (canonical LedgerService::cashFlow for the day).
        public readonly float $cashOpening,
        public readonly float $cashIn,
        public readonly float $cashOut,
        public readonly float $cashClosing,
    ) {
    }
}

<?php

namespace App\Reporting;

use App\Reporting\Data\DailyClosingData;

/**
 * Daily closing aggregation (Phase 3). Combines the existing canonical reporting
 * services for one date — GstReportingService (finalized sales + GST) and
 * LedgerService::cashFlow (cash movement). It is an AGGREGATOR: it delegates all
 * sales / GST / cash math to those services and never re-implements it, so the
 * closing totals reconcile by construction to the GST Report, the Sales Register
 * (same salesIn scope), and the Cash Flow report for the same date.
 */
class ClosingService
{
    public function __construct(
        private readonly GstReportingService $gst,
        private readonly LedgerService $ledger,
    ) {
    }

    public function dailyClosing(int $shopId, string $date): DailyClosingData
    {
        $period = ReportPeriod::day($date);

        $gstData = $this->gst->summary($shopId, $period);
        $cash = $this->ledger->cashFlow($shopId, $period);

        return new DailyClosingData(
            date: $date,
            totalSales: $gstData->totalSales,
            discount: $gstData->totalDiscount,
            taxable: $gstData->taxableAmount,
            cgst: $gstData->cgstCollected,
            sgst: $gstData->sgstCollected,
            igst: $gstData->igstCollected,
            gstCollected: $gstData->gstCollected,
            invoiceCount: $gstData->invoiceCount,
            cashOpening: $cash->opening,
            cashIn: $cash->cashIn,
            cashOut: $cash->cashOut,
            cashClosing: $cash->closing,
        );
    }
}

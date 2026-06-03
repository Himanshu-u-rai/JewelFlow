<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Cash day report (legacy report extracted to the Reporting layer, M5).
 *
 * One day's cash ledger: totals grouped by transaction type, plus a
 * payment-mode breakdown. Aggregation moved out of CashReportController with
 * no behaviour change. Reads cash_transactions only.
 */
final class CashDayData
{
    public function __construct(
        public readonly Collection $rows,           // {type, total} per transaction type
        public readonly Collection $modeBreakdown,  // total keyed by payment_mode
    ) {}
}

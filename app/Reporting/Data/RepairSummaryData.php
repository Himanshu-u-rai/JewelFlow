<?php

namespace App\Reporting\Data;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Repair jobs report (legacy report extracted to the Reporting layer, M5).
 *
 * Filtered, paginated repair list plus aggregate totals (gold issued / returned
 * fine weight, delivered-job cash). Aggregation moved out of
 * RepairReportController with no behaviour change. Reads repairs only.
 */
final class RepairSummaryData
{
    public function __construct(
        public readonly LengthAwarePaginator $repairs,
        public readonly object $totals,   // {total_cash}
    ) {}
}

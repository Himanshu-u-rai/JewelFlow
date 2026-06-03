<?php

namespace App\Reporting;

use App\Models\Repair;
use App\Reporting\Data\RepairSummaryData;

/**
 * Repair reporting (legacy report extracted to the Reporting layer, M5).
 *
 * Filtered, paginated repair list plus aggregate totals. Aggregation moved out
 * of RepairReportController with no behaviour change. Reads repairs only.
 */
class RepairService
{
    public function summary(int $shopId, ?string $fromDate, ?string $toDate, ?string $status): RepairSummaryData
    {
        $query = Repair::where('shop_id', $shopId)->with(['customer', 'invoice']);
        $this->applyFilters($query, $fromDate, $toDate, $status);

        $repairs = $query->orderBy('created_at', 'desc')->paginate(25)->withQueryString();

        $totalsQuery = Repair::where('shop_id', $shopId);
        $this->applyFilters($totalsQuery, $fromDate, $toDate, $status);

        $totals = $totalsQuery->selectRaw("
            COALESCE(SUM(gold_issued_fine), 0) as total_issued,
            COALESCE(SUM(gold_returned_fine), 0) as total_returned,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN final_cost ELSE 0 END), 0) as total_cash
        ")->first();

        return new RepairSummaryData(repairs: $repairs, totals: $totals);
    }

    private function applyFilters($query, ?string $fromDate, ?string $toDate, ?string $status): void
    {
        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }
        if ($status) {
            $query->where('status', $status);
        }
    }
}

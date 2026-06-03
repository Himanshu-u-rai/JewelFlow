<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Reporting\AuditService;
use App\Reporting\Export\CsvReportExporter;
use App\Reporting\ReportPeriod;
use Illuminate\Http\Request;

/**
 * Operator / audit reporting (Phase 2 M4). Thin orchestration — aggregation in
 * AuditService.
 */
class AuditReportController extends Controller
{
    public function __construct(private AuditService $audit) {}

    private function shopId(): int
    {
        return (int) auth()->user()->shop_id;
    }

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::month($request->input('year'), $request->input('month'));
    }

    public function operatorPerformance(Request $request)
    {
        $period = $this->period($request);
        $data = $this->audit->operatorPerformance($this->shopId(), $period);

        return view('reports.operator-performance', [
            'data'   => $data,
            'period' => $period,
            'month'  => (int) $period->start()->month,
            'year'   => (int) $period->start()->year,
        ]);
    }

    public function operatorPerformanceCsv(Request $request)
    {
        $period = $this->period($request);
        $data = $this->audit->operatorPerformance($this->shopId(), $period);

        $headers = ['Operator', 'Invoices', 'Sales', 'Discount', 'Returns', 'Returns Value', 'Net Sales'];
        $rows = $data->rows->map(fn ($r) => [
            $r->operator_name,
            $r->invoice_count,
            number_format($r->total_sales, 2, '.', ''),
            number_format($r->total_discount, 2, '.', ''),
            $r->returns_count,
            number_format($r->returns_value, 2, '.', ''),
            number_format($r->net_sales, 2, '.', ''),
        ])->all();

        return CsvReportExporter::fromRows('operator-performance-' . $period->start()->format('Y-m') . '.csv', $headers, $rows);
    }
}

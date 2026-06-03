<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Reporting\Export\CsvReportExporter;
use App\Reporting\KarigarService;
use App\Reporting\ReportPeriod;
use Illuminate\Http\Request;

/**
 * Karigar reporting (Phase 2 M4). Thin orchestration — aggregation in
 * KarigarService.
 */
class KarigarReportController extends Controller
{
    public function __construct(private KarigarService $karigar) {}

    private function shopId(): int
    {
        return (int) auth()->user()->shop_id;
    }

    public function settlement()
    {
        $data = $this->karigar->settlement($this->shopId());

        return view('reports.karigar-settlement', ['data' => $data]);
    }

    public function settlementCsv()
    {
        $data = $this->karigar->settlement($this->shopId());

        $headers = ['Karigar', 'Open Jobs', 'Issued (g)', 'Received (g)', 'Wastage (g)', 'Outstanding (g)', 'Invoiced', 'Paid', 'Payable'];
        $rows = $data->rows->map(fn ($r) => [
            $r->karigar_name,
            $r->open_jobs,
            number_format($r->issued_fine, 4, '.', ''),
            number_format($r->received_fine, 4, '.', ''),
            number_format($r->wastage_fine, 4, '.', ''),
            number_format($r->outstanding_fine, 4, '.', ''),
            number_format($r->invoiced, 2, '.', ''),
            number_format($r->paid, 2, '.', ''),
            number_format($r->outstanding_payable, 2, '.', ''),
        ])->all();

        return CsvReportExporter::fromRows('karigar-settlement-' . now()->format('Y-m-d') . '.csv', $headers, $rows);
    }

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::month($request->input('year'), $request->input('month'));
    }

    public function shrinkage(Request $request)
    {
        $period = $this->period($request);
        $data = $this->karigar->shrinkage($this->shopId(), $period);

        return view('reports.shrinkage', [
            'data'   => $data,
            'period' => $period,
            'month'  => (int) $period->start()->month,
            'year'   => (int) $period->start()->year,
        ]);
    }

    public function shrinkageCsv(Request $request)
    {
        $period = $this->period($request);
        $data = $this->karigar->shrinkage($this->shopId(), $period);

        $headers = ['Karigar', 'Jobs', 'Issued (g)', 'In Items (g)', 'Leftover (g)', 'Wastage (g)', 'Wastage %', 'Unaccounted (g)'];
        $rows = $data->rows->map(fn ($r) => [
            $r->karigar_name,
            $r->job_count,
            number_format($r->issued_fine, 4, '.', ''),
            number_format($r->returned_fine, 4, '.', ''),
            number_format($r->leftover_fine, 4, '.', ''),
            number_format($r->wastage_fine, 4, '.', ''),
            number_format($r->wastage_pct, 2, '.', ''),
            number_format($r->unaccounted_fine, 4, '.', ''),
        ])->all();

        return CsvReportExporter::fromRows('shrinkage-' . $period->start()->format('Y-m') . '.csv', $headers, $rows);
    }
}

<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Reporting\Export\CsvReportExporter;
use App\Reporting\KarigarService;

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
}

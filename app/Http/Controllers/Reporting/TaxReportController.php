<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Reporting\Export\CsvReportExporter;
use App\Reporting\ReportPeriod;
use App\Reporting\TaxService;
use Illuminate\Http\Request;

/**
 * CA / compliance tax reports (Phase 2 M1). Thin orchestration only — all
 * aggregation lives in TaxService → GstReportingService.
 */
class TaxReportController extends Controller
{
    public function __construct(private TaxService $tax) {}

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::month($request->input('year'), $request->input('month'));
    }

    private function shopId(): int
    {
        return (int) auth()->user()->shop_id;
    }

    // ---- GSTR-1 ----

    public function gstr1(Request $request)
    {
        $period = $this->period($request);
        $data = $this->tax->gstr1($this->shopId(), $period);

        return view('reports.tax.gstr1', [
            'data'        => $data,
            'period'      => $period,
            'month'       => (int) $period->start()->month,
            'year'        => (int) $period->start()->year,
        ]);
    }

    // gstr1Csv() retired (Phase 3 Cleanup #1) — GSTR-1 CSV is produced by the
    // reporting spine (clean per-section ZIP). See COMPLIANCE_CSV_MIGRATION_NOTE.md.

    // ---- GSTR-3B support ----

    public function gstr3b(Request $request)
    {
        $period = $this->period($request);
        $data = $this->tax->gstr3b($this->shopId(), $period);

        return view('reports.tax.gstr3b', [
            'data'   => $data,
            'period' => $period,
            'month'  => (int) $period->start()->month,
            'year'   => (int) $period->start()->year,
        ]);
    }

    // ---- Credit / Debit note register ----

    public function creditNoteRegister(Request $request)
    {
        $period = $this->period($request);
        $data = $this->tax->creditNoteRegister($this->shopId(), $period);

        return view('reports.tax.cn-register', [
            'data'   => $data,
            'period' => $period,
            'month'  => (int) $period->start()->month,
            'year'   => (int) $period->start()->year,
        ]);
    }

    // creditNoteRegisterCsv() retired (Phase 3 Cleanup #1) — the Credit Note
    // Register CSV is produced by the reporting spine (clean single CSV).
    // See COMPLIANCE_CSV_MIGRATION_NOTE.md.
}

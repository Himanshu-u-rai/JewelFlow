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

    public function gstr1Csv(Request $request)
    {
        $period = $this->period($request);
        $data = $this->tax->gstr1($this->shopId(), $period);

        $rows = [];
        $rows[] = ['GSTR-1', $period->label()];
        $rows[] = [];
        $rows[] = ['== B2B (registered buyers) =='];
        $rows[] = ['Invoice', 'Date', 'Buyer GSTIN', 'Place of Supply', 'Rate %', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total GST', 'Invoice Total'];
        foreach ($data->b2b as $r) {
            $rows[] = [
                $r->invoice_number,
                \Carbon\Carbon::parse($r->doc_date)->format('Y-m-d'),
                $r->buyer_gstin,
                $r->place_of_supply_state_code ?? '',
                number_format((float) $r->gst_rate, 2, '.', ''),
                number_format((float) $r->taxable, 2, '.', ''),
                number_format((float) $r->cgst, 2, '.', ''),
                number_format((float) $r->sgst, 2, '.', ''),
                number_format((float) $r->igst, 2, '.', ''),
                number_format((float) $r->gst, 2, '.', ''),
                number_format((float) $r->total, 2, '.', ''),
            ];
        }
        $rows[] = [];
        $rows[] = ['== B2CS (consumers) =='];
        $rows[] = ['Rate %', 'Place of Supply', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total GST', 'Invoices'];
        foreach ($data->b2cs as $r) {
            $rows[] = [
                number_format((float) $r->gst_rate, 2, '.', ''),
                $r->place_of_supply_state_code ?? '',
                number_format((float) $r->taxable, 2, '.', ''),
                number_format((float) $r->cgst, 2, '.', ''),
                number_format((float) $r->sgst, 2, '.', ''),
                number_format((float) $r->igst, 2, '.', ''),
                number_format((float) $r->gst, 2, '.', ''),
                (int) $r->count,
            ];
        }
        $rows[] = [];
        $rows[] = ['== HSN Summary =='];
        $rows[] = ['HSN', 'Taxable', 'GST', 'Lines'];
        foreach ($data->hsnSummary as $r) {
            $rows[] = [
                $r->hsn_code,
                number_format((float) $r->taxable, 2, '.', ''),
                number_format((float) $r->gst, 2, '.', ''),
                (int) $r->lines,
            ];
        }

        return CsvReportExporter::fromRows('gstr1-' . $period->start()->format('Y-m') . '.csv', ['Section'], $rows);
    }

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

    public function creditNoteRegisterCsv(Request $request)
    {
        $period = $this->period($request);
        $data = $this->tax->creditNoteRegister($this->shopId(), $period);

        $headers = ['CN Number', 'Date', 'Type', 'Original Invoice', 'Original Invoice Date', 'Customer', 'Rate %', 'Taxable', 'CGST', 'SGST', 'IGST', 'GST', 'CN Total'];
        $rows = $data->rows->map(fn ($r) => [
            $r->credit_note_number,
            \Carbon\Carbon::parse($r->issued_at)->format('Y-m-d'),
            $r->cn_type,
            $r->original_invoice_number ?? '',
            $r->original_invoice_date ? \Carbon\Carbon::parse($r->original_invoice_date)->format('Y-m-d') : '',
            $r->customer_name,
            number_format((float) $r->gst_rate, 2, '.', ''),
            number_format((float) $r->taxable, 2, '.', ''),
            number_format((float) $r->cgst, 2, '.', ''),
            number_format((float) $r->sgst, 2, '.', ''),
            number_format((float) $r->igst, 2, '.', ''),
            number_format((float) $r->gst, 2, '.', ''),
            number_format((float) $r->total, 2, '.', ''),
        ])->all();

        return CsvReportExporter::fromRows('credit-note-register-' . $period->start()->format('Y-m') . '.csv', $headers, $rows);
    }
}

<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Reporting\Export\CsvReportExporter;
use App\Reporting\ReceivablesService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Receivables & liability pack (Phase 2 M3). Thin orchestration only —
 * aggregation lives in ReceivablesService.
 */
class ReceivablesReportController extends Controller
{
    public function __construct(private ReceivablesService $receivables) {}

    private function shopId(): int
    {
        return (int) auth()->user()->shop_id;
    }

    private function asOf(Request $request): Carbon
    {
        $raw = $request->input('as_of');
        try {
            return $raw ? Carbon::parse($raw) : Carbon::now();
        } catch (\Throwable) {
            return Carbon::now();
        }
    }

    // ---- #8 Customer dues aging ----

    public function duesAging(Request $request)
    {
        $asOf = $this->asOf($request);
        $data = $this->receivables->duesAging($this->shopId(), $asOf);

        return view('reports.dues-aging', [
            'data'  => $data,
            'asOf'  => $asOf->format('Y-m-d'),
        ]);
    }

    public function duesAgingCsv(Request $request)
    {
        $asOf = $this->asOf($request);
        $data = $this->receivables->duesAging($this->shopId(), $asOf);

        $headers = ['Customer', 'Mobile', 'Invoices', 'Current (0-30)', '31-60', '61-90', '90+', 'Total Outstanding'];
        $rows = $data->rows->map(fn ($r) => [
            $r->customer_name,
            $r->mobile ?? '',
            $r->invoice_count,
            number_format($r->current, 2, '.', ''),
            number_format($r->d3160, 2, '.', ''),
            number_format($r->d6190, 2, '.', ''),
            number_format($r->d90plus, 2, '.', ''),
            number_format($r->total, 2, '.', ''),
        ])->all();

        return CsvReportExporter::fromRows('dues-aging-' . $data->asOf . '.csv', $headers, $rows);
    }

    // ---- #9 Pending EMI / installment visibility ----

    public function emi(Request $request)
    {
        $asOf = $this->asOf($request);
        $data = $this->receivables->emiVisibility($this->shopId(), $asOf);

        return view('reports.emi-visibility', [
            'data' => $data,
            'asOf' => $asOf->format('Y-m-d'),
        ]);
    }

    public function emiCsv(Request $request)
    {
        $asOf = $this->asOf($request);
        $data = $this->receivables->emiVisibility($this->shopId(), $asOf);

        $headers = ['Customer', 'Invoice', 'Total Payable', 'Paid', 'Remaining', 'EMIs', 'Next Due', 'Overdue Days'];
        $rows = $data->rows->map(fn ($r) => [
            $r->customer_name,
            $r->invoice_number ?? '',
            number_format($r->total_payable, 2, '.', ''),
            number_format($r->paid, 2, '.', ''),
            number_format($r->remaining, 2, '.', ''),
            $r->emis_paid . '/' . $r->total_emis,
            $r->next_due_date ? \Carbon\Carbon::parse($r->next_due_date)->format('Y-m-d') : '',
            $r->overdue ? $r->days_overdue : '0',
        ])->all();

        return CsvReportExporter::fromRows('emi-visibility-' . $data->asOf . '.csv', $headers, $rows);
    }

    // ---- #10 Scheme liability exposure ----

    public function schemeLiability()
    {
        $data = $this->receivables->schemeLiability($this->shopId());

        return view('reports.scheme-liability', ['data' => $data]);
    }

    public function schemeLiabilityCsv()
    {
        $data = $this->receivables->schemeLiability($this->shopId());

        $headers = ['Customer', 'Scheme', 'Status', 'Contributed', 'Bonus Accrued', 'Current Balance', 'Maturity Date'];
        $rows = $data->rows->map(fn ($r) => [
            $r->customer_name,
            $r->scheme_name ?? '',
            $r->status,
            number_format($r->total_paid, 2, '.', ''),
            number_format($r->bonus_accrued, 2, '.', ''),
            number_format($r->current_balance, 2, '.', ''),
            $r->maturity_date ? \Carbon\Carbon::parse($r->maturity_date)->format('Y-m-d') : '',
        ])->all();

        return CsvReportExporter::fromRows('scheme-liability-' . now()->format('Y-m-d') . '.csv', $headers, $rows);
    }

    // ---- #11 Metal / old-gold liability ----

    public function metalLiability()
    {
        $data = $this->receivables->metalLiability($this->shopId());

        return view('reports.metal-liability', ['data' => $data]);
    }

    public function metalLiabilityCsv()
    {
        $data = $this->receivables->metalLiability($this->shopId());

        $headers = ['Customer', 'Fine Gold Deposited (g)'];
        $rows = $data->rows->map(fn ($r) => [
            $r->customer_name,
            number_format($r->fine_deposited, 4, '.', ''),
        ])->all();

        return CsvReportExporter::fromRows('metal-liability-' . now()->format('Y-m-d') . '.csv', $headers, $rows);
    }
}

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
}

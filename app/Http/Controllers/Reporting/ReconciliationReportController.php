<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Reporting\Export\CsvReportExporter;
use App\Reporting\InventoryService;
use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
use App\Reporting\SalesService;
use Illuminate\Http\Request;

/**
 * CA Ledger & Reconciliation pack (Phase 2 M2). Thin orchestration only —
 * aggregation lives in SalesService / LedgerService / InventoryService.
 */
class ReconciliationReportController extends Controller
{
    public function __construct(
        private SalesService $sales,
        private LedgerService $ledger,
        private InventoryService $inventory,
    ) {}

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::month($request->input('year'), $request->input('month'));
    }

    private function shopId(): int
    {
        return (int) auth()->user()->shop_id;
    }

    // ---- Payment reconciliation ----

    public function paymentReconciliation(Request $request)
    {
        $period = $this->period($request);
        $data = $this->sales->paymentReconciliation($this->shopId(), $period);

        return view('reports.payment-reconciliation', [
            'data'   => $data,
            'period' => $period,
            'month'  => (int) $period->start()->month,
            'year'   => (int) $period->start()->year,
        ]);
    }

    public function paymentReconciliationCsv(Request $request)
    {
        $period = $this->period($request);
        $data = $this->sales->paymentReconciliation($this->shopId(), $period);

        $headers = ['Invoice', 'Date', 'Customer', 'Invoice Total', 'Collected', 'Pending', 'Status'];
        $rows = $data->rows->map(fn ($r) => [
            $r->invoice_number,
            \Carbon\Carbon::parse($r->doc_date)->format('Y-m-d'),
            $r->customer_name,
            number_format($r->total, 2, '.', ''),
            number_format($r->collected, 2, '.', ''),
            number_format($r->pending, 2, '.', ''),
            $r->status,
        ])->all();

        return CsvReportExporter::fromRows('payment-reconciliation-' . $period->start()->format('Y-m') . '.csv', $headers, $rows);
    }

    // ---- Day-book / journal ----

    public function dayBook(Request $request)
    {
        $period = $this->period($request);
        $data = $this->ledger->dayBook($this->shopId(), $period);

        return view('reports.day-book', [
            'data'   => $data,
            'period' => $period,
            'month'  => (int) $period->start()->month,
            'year'   => (int) $period->start()->year,
        ]);
    }

    public function dayBookCsv(Request $request)
    {
        $period = $this->period($request);
        $data = $this->ledger->dayBook($this->shopId(), $period);

        $headers = ['Date/Time', 'Event', 'Reference', 'Party', 'Amount', 'Direction', 'Source'];
        $rows = $data->events->map(fn ($e) => [
            \Carbon\Carbon::parse($e->occurred_at)->format('Y-m-d H:i:s'),
            $e->event_type,
            $e->reference,
            $e->party,
            number_format($e->amount, 2, '.', ''),
            $e->direction,
            $e->source,
        ])->all();

        return CsvReportExporter::fromRows('day-book-' . $period->start()->format('Y-m') . '.csv', $headers, $rows);
    }

    // ---- Inventory valuation (snapshot) ----

    public function inventoryValuation(Request $request)
    {
        $days = (int) $request->input('dead_days', 90);
        $data = $this->inventory->valuation($this->shopId(), max(1, $days));

        return view('reports.inventory-valuation', ['data' => $data]);
    }

    public function inventoryValuationCsv(Request $request)
    {
        $days = max(1, (int) $request->input('dead_days', 90));
        $data = $this->inventory->valuation($this->shopId(), $days);

        $rows = [];
        $rows[] = ['Inventory Valuation (at cost)', now()->format('Y-m-d H:i')];
        $rows[] = ['Total at cost', number_format($data->totalAtCost, 2, '.', '')];
        $rows[] = ['Total at retail (tag price)', number_format($data->totalAtRetail, 2, '.', '')];
        $rows[] = ['Items on hand', $data->itemCount];
        $rows[] = ['Cost unknown', $data->costUnknownCount];
        $rows[] = ["Dead capital (> {$data->deadCapitalDays}d) value", number_format($data->deadCapitalValue, 2, '.', '')];
        $rows[] = [];
        $rows[] = ['== By Category =='];
        $rows[] = ['Category', 'Count', 'Cost Value', 'Retail Value'];
        foreach ($data->byCategory as $c) {
            $rows[] = [$c->category, (int) $c->count, number_format((float) $c->cost_value, 2, '.', ''), number_format((float) $c->retail_value, 2, '.', '')];
        }
        $rows[] = [];
        $rows[] = ['== By Metal =='];
        $rows[] = ['Metal', 'Count', 'Cost Value', 'Fine Weight (g)'];
        foreach ($data->byMetal as $m) {
            $rows[] = [$m->metal_type, (int) $m->count, number_format((float) $m->cost_value, 2, '.', ''), number_format((float) $m->fine_weight, 3, '.', '')];
        }

        return CsvReportExporter::fromRows('inventory-valuation-' . now()->format('Y-m-d') . '.csv', ['Inventory Valuation'], $rows);
    }
}

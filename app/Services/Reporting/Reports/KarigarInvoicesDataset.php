<?php

namespace App\Services\Reporting\Reports;

use App\Models\KarigarInvoice;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\FilterControl as Filter;
use App\Services\Reporting\Definition\FilterKey as FK;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;

/**
 * Karigar Invoices — labour/work invoices raised by karigars (pieces, weights,
 * making + tax, paid vs outstanding). Accounting data export, period filtered on
 * karigar_invoice_date, filterable by karigar. Consumes the persisted invoice
 * rows (an append-only labour ledger); outstanding is the model's own accessor.
 */
class KarigarInvoicesDataset extends ReportDatasetService
{
    public const KEY = 'karigar-invoices';
    public const VERSION = 'karigar-invoices@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Karigar Invoices',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('invoice_number', 'Invoice No.', T::String),
                Col::mandatory('invoice_date', 'Date', T::Date),
                Col::mandatory('karigar', 'Karigar', T::String),
                Col::optional('mode', 'Mode', T::String),
                Col::optional('total_pieces', 'Pieces', T::Integer),
                Col::optional('total_net_weight', 'Net Wt (g)', T::Weight),
                Col::optional('total_making_amount', 'Making', T::Money),
                Col::optional('total_tax', 'Tax', T::Money),
                Col::mandatory('total_after_tax', 'Total', T::Money),
                Col::optional('amount_paid', 'Paid', T::Money),
                Col::mandatory('outstanding', 'Outstanding', T::Money),
                Col::optional('payment_status', 'Status', T::String),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::Raw],
            filters: [
                Filter::for(FK::Period),
                Filter::for(FK::Karigar),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $invoices = $this->query($request)->with('karigar')->get();

        $rows = [];
        $afterTax = 0.0;
        $outstanding = 0.0;
        foreach ($invoices as $ki) {
            $out = max(0.0, (float) $ki->total_after_tax - (float) $ki->amount_paid);
            $rows[] = [
                'invoice_number' => $ki->karigar_invoice_number,
                'invoice_date' => $ki->karigar_invoice_date,
                'karigar' => $ki->karigar?->name ?? '—',
                'mode' => ucfirst(str_replace('_', ' ', (string) $ki->mode)),
                'total_pieces' => (int) $ki->total_pieces,
                'total_net_weight' => (float) $ki->total_net_weight,
                'total_making_amount' => (float) $ki->total_making_amount,
                'total_tax' => (float) $ki->total_tax,
                'total_after_tax' => (float) $ki->total_after_tax,
                'amount_paid' => (float) $ki->amount_paid,
                'outstanding' => round($out, 2),
                'payment_status' => ucfirst((string) $ki->payment_status),
            ];
            $afterTax += (float) $ki->total_after_tax;
            $outstanding += $out;
        }

        $keys = $request->columnKeys;
        $totals = [];
        if (in_array('total_after_tax', $keys, true)) {
            $totals['total_after_tax'] = round($afterTax, 2);
        }
        if (in_array('outstanding', $keys, true)) {
            $totals['outstanding'] = round($outstanding, 2);
        }

        $section = new ReportSection('karigar_invoices', 'Karigar Invoices', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = KarigarInvoice::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('karigar_invoice_date', [$period['from'], $period['to']]);
        }
        if ($karigar = $request->filter('karigar')) {
            $q->where('karigar_id', $karigar);
        }

        return $q->orderBy('karigar_invoice_date')->orderBy('id');
    }
}

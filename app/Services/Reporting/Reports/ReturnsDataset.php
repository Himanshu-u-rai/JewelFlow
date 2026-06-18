<?php

namespace App\Services\Reporting\Reports;

use App\Models\ReturnOrder;
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
use Illuminate\Support\Str;

/**
 * Returns — customer return orders (type, status, original invoice, credit-note
 * total). Accounting data export, period filtered on created_at. Consumes the
 * persisted return rows; the refund total comes from the linked credit note.
 */
class ReturnsDataset extends ReportDatasetService
{
    public const KEY = 'returns';
    public const VERSION = 'returns@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Returns',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('created_at', 'Date', T::DateTime),
                Col::mandatory('return_type', 'Type', T::String),
                Col::mandatory('status', 'Status', T::String),
                Col::optional('invoice_number', 'Original Invoice', T::String),
                Col::optional('customer', 'Customer', T::String),
                Col::optional('credit_note_number', 'Credit Note', T::String),
                Col::mandatory('refund_total', 'Refund Total', T::Money),
                Col::optional('reason', 'Reason', T::String),
                Col::optional('settled_at', 'Settled', T::DateTime),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::Raw],
            filters: [
                Filter::for(FK::Period),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $returns = $this->query($request)->with(['invoice', 'customer', 'creditNote'])->get();

        $rows = [];
        $refund = 0.0;
        foreach ($returns as $ro) {
            $cnTotal = (float) ($ro->creditNote?->total ?? 0);
            $rows[] = [
                'created_at' => $ro->created_at,
                'return_type' => Str::headline((string) $ro->return_type),
                'status' => Str::headline((string) $ro->status),
                'invoice_number' => $ro->invoice?->invoice_number ?? '—',
                'customer' => $ro->customer?->name ?? 'Walk-in',
                'credit_note_number' => $ro->creditNote?->credit_note_number ?? '—',
                'refund_total' => $cnTotal,
                'reason' => $ro->reason,
                'settled_at' => $ro->settled_at,
            ];
            $refund += $cnTotal;
        }

        $keys = $request->columnKeys;
        $totals = in_array('refund_total', $keys, true) ? ['refund_total' => round($refund, 2)] : [];

        $section = new ReportSection('returns', 'Returns', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = ReturnOrder::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('created_at', [$period['from'], $period['to']]);
        }

        return $q->orderBy('created_at')->orderBy('id');
    }
}

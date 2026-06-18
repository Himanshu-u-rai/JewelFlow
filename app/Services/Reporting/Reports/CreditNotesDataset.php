<?php

namespace App\Services\Reporting\Reports;

use App\Models\CreditNote;
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
 * Credit Notes — issued credit notes with amounts and the original invoice link
 * (a data-dump view; the GSTR-focused register is the separate cn-register
 * report). Accounting data export, period filtered on issued_at. Credit notes
 * are immutable once issued — read-only here.
 */
class CreditNotesDataset extends ReportDatasetService
{
    public const KEY = 'credit-notes';
    public const VERSION = 'credit-notes@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Credit Notes',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('credit_note_number', 'CN No.', T::String),
                Col::mandatory('issued_at', 'Issued', T::DateTime),
                Col::optional('customer', 'Customer', T::String),
                Col::optional('invoice_number', 'Original Invoice', T::String),
                Col::optional('subtotal', 'Subtotal', T::Money),
                Col::optional('gst', 'GST', T::Money),
                Col::mandatory('total', 'Total', T::Money),
                Col::mandatory('status', 'Status', T::String),
                Col::optional('reason', 'Reason', T::String),
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
        $notes = $this->query($request)->with(['customer', 'invoice'])->get();

        $rows = [];
        $total = 0.0;
        foreach ($notes as $cn) {
            $rows[] = [
                'credit_note_number' => $cn->credit_note_number,
                'issued_at' => $cn->issued_at,
                'customer' => $cn->customer?->name ?? 'Walk-in',
                'invoice_number' => $cn->invoice?->invoice_number ?? '—',
                'subtotal' => (float) $cn->subtotal,
                'gst' => (float) $cn->gst,
                'total' => (float) $cn->total,
                'status' => Str::headline((string) $cn->status),
                'reason' => $cn->reason,
            ];
            $total += (float) $cn->total;
        }

        $keys = $request->columnKeys;
        $totals = in_array('total', $keys, true) ? ['total' => round($total, 2)] : [];

        $section = new ReportSection('credit_notes', 'Credit Notes', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = CreditNote::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('issued_at', [$period['from'], $period['to']]);
        }

        return $q->orderBy('issued_at')->orderBy('id');
    }
}

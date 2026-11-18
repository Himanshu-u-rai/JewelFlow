<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\DocumentsIssuedService;
use App\Reporting\ReportPeriod;
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
 * Documents issued (GSTR-1 table 13) — invoices and credit notes issued in a month or a financial year,
 * per series: first and last number, how many were issued, how many cancelled, net issued.
 * Quick bills are not included (owner, 2026-11-16: handled separately).
 */
class DocumentsIssuedDataset extends ReportDatasetService
{
    public const KEY = 'gst-documents-issued';
    public const VERSION = 'gst-documents-issued@1';

    public function __construct(private readonly DocumentsIssuedService $documents)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Documents issued',
            classification: Cls::Compliance,
            columns: [
                Col::mandatory('doc_type', 'Nature of document', T::String),
                Col::mandatory('series', 'Series', T::String),
                Col::mandatory('from', 'Sr. No. From', T::String),
                Col::mandatory('to', 'Sr. No. To', T::String),
                Col::mandatory('total', 'Total Number', T::Integer),
                Col::mandatory('cancelled', 'Cancelled', T::Integer),
                Col::mandatory('net', 'Net Issued', T::Integer),
                Col::mandatory('note', 'Basis of this report', T::String),
            ],
            profiles: [P::Fixed],
            filters: [
                Filter::for(FK::Period, true),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default()->withView('reports.money'),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $rows = $this->documents->summary($request->shopId, $this->period($request));

        $documents = new ReportSection('documents', 'Documents issued', $this->cols($def, ['doc_type', 'series', 'from', 'to', 'total', 'cancelled', 'net']), $rows);

        $notes = [
            'Invoices are placed in the period of their bill date (the date the bill was made); credit notes by the date they were issued.',
            'Cancelled = cancelled invoices, plus numbers voided when a draft moved into the next financial year and took a new number there, plus drafts that were never billed (their number was used and will not appear on any sale).',
            'A draft that is still open counts as cancelled until it is billed; once billed it counts as issued in the year it was billed.',
            'Cancelling a bill in the app also issues a reversal bill with its own number; that number counts as issued.',
            'Net issued = total number minus cancelled. Credit notes cannot be cancelled, so their cancelled count is 0.',
            'Series is the number with its running part removed; a new series (for example a new year in a yearly numbering) is a separate row.',
            'Quick bills are not included in this report.',
        ];
        $basis = new ReportSection('basis', 'Basis of this report', $this->cols($def, ['note']), array_map(static fn ($n) => ['note' => $n], $notes));

        return new ReportDataset([$documents, $basis], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return count($this->documents->summary($request->shopId, $this->period($request)));
    }

    /**
     * @param  string[]  $keys
     * @return \App\Services\Reporting\Definition\ColumnDefinition[]
     */
    private function cols(ReportDefinition $def, array $keys): array
    {
        return array_values(array_filter(array_map(static fn ($k) => $def->column($k), $keys)));
    }

    private function period(ReportRequest $request): ReportPeriod
    {
        $period = $request->filter('period', []);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        return ReportPeriod::range($from ? $from->toDateString() : null, $to ? $to->toDateString() : null);
    }
}

<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\GstReportingService;
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
 * GST Summary — the rate-wise output-tax position for a period (COMPLIANCE).
 *
 * Reconciliation contract: wraps GstReportingService::summary() verbatim. The
 * per-rate breakdown becomes the rows; the grand totals are summed from those
 * rows and equal the DTO's own scalars (taxableAmount, cgst/sgst/igstCollected,
 * gstCollected, totalSales, invoiceCount) by construction — no re-query, so the
 * GST report can never disagree with the canonical service.
 *
 * Rigid (frozen §9): single Fixed profile, every column mandatory.
 */
class GstReportDataset extends ReportDatasetService
{
    public const KEY = 'gst';
    public const VERSION = 'gst@1';

    public function __construct(private readonly GstReportingService $gst)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'GST Summary',
            classification: Cls::Compliance,
            columns: [
                Col::mandatory('rate', 'GST Rate', T::String),
                Col::mandatory('taxable', 'Taxable Value', T::Money),
                Col::mandatory('cgst', 'CGST', T::Money),
                Col::mandatory('sgst', 'SGST', T::Money),
                Col::mandatory('igst', 'IGST', T::Money),
                Col::mandatory('total_gst', 'Total GST', T::Money),
                Col::mandatory('total', 'Total', T::Money),
                Col::mandatory('count', 'Invoices', T::Integer),
            ],
            profiles: [P::Fixed],
            filters: [
                Filter::for(FK::Period, true),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $data = $this->gst->summary($request->shopId, $this->period($request));

        $rows = [];
        foreach ($data->breakdown as $r) {
            $rows[] = [
                'rate' => (string) $r->gst_rate . '%',
                'taxable' => (float) $r->taxable,
                'cgst' => (float) $r->cgst,
                'sgst' => (float) $r->sgst,
                'igst' => (float) $r->igst,
                'total_gst' => (float) $r->gst,
                'total' => (float) $r->total,
                'count' => (int) $r->count,
            ];
        }

        // Totals come straight from the canonical DTO scalars — equal to the
        // row sums by construction (the breakdown is the same source).
        $totals = [
            'taxable' => round($data->taxableAmount, 2),
            'cgst' => round($data->cgstCollected, 2),
            'sgst' => round($data->sgstCollected, 2),
            'igst' => round($data->igstCollected, 2),
            'total_gst' => round($data->gstCollected, 2),
            'total' => round($data->totalSales, 2),
            'count' => $data->invoiceCount,
        ];

        $section = new ReportSection('gst', 'GST Summary', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->gst->summary($request->shopId, $this->period($request))->breakdown->count();
    }

    private function period(ReportRequest $request): ReportPeriod
    {
        $period = $request->filter('period', []);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        return ReportPeriod::range(
            $from ? $from->toDateString() : null,
            $to ? $to->toDateString() : null,
        );
    }
}

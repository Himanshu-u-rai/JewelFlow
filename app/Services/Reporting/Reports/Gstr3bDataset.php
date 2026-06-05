<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\ReportPeriod;
use App\Reporting\TaxService;
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
 * GSTR-3B — the net output-tax summary for a period (COMPLIANCE).
 *
 * Wraps TaxService::gstr3b() verbatim. Three fixed rows ARE the summary
 * (outward supplies, less credit notes, net liability), so there is no totals
 * row. Reconciles by construction: the outward row equals the DTO's outward
 * scalars and the net row equals netTaxable/netGst.
 *
 * Rigid (frozen §9): single Fixed profile, every column mandatory.
 */
class Gstr3bDataset extends ReportDatasetService
{
    public const KEY = 'gstr3b';
    public const VERSION = 'gstr3b@1';

    public function __construct(private readonly TaxService $tax)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'GSTR-3B Summary',
            classification: Cls::Compliance,
            columns: [
                Col::mandatory('particulars', 'Particulars', T::String),
                Col::mandatory('taxable', 'Taxable Value', T::Money),
                Col::mandatory('cgst', 'CGST', T::Money),
                Col::mandatory('sgst', 'SGST', T::Money),
                Col::mandatory('igst', 'IGST', T::Money),
                Col::mandatory('total_gst', 'Total GST', T::Money),
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
        $data = $this->tax->gstr3b($request->shopId, $this->period($request));

        $rows = [
            [
                'particulars' => 'Outward taxable supplies',
                'taxable' => round($data->outwardTaxable, 2),
                'cgst' => round($data->outwardCgst, 2),
                'sgst' => round($data->outwardSgst, 2),
                'igst' => round($data->outwardIgst, 2),
                'total_gst' => round($data->outwardGst, 2),
            ],
            [
                'particulars' => 'Less: Credit notes (returns)',
                'taxable' => round(-$data->cnTaxable, 2),
                'cgst' => null,
                'sgst' => null,
                'igst' => null,
                'total_gst' => round(-$data->cnGst, 2),
            ],
            [
                'particulars' => 'Net tax liability',
                'taxable' => round($data->netTaxable, 2),
                'cgst' => null,
                'sgst' => null,
                'igst' => null,
                'total_gst' => round($data->netGst, 2),
            ],
        ];

        // The rows ARE the summary — no grand-total row.
        $section = new ReportSection('gstr3b', 'GSTR-3B Summary', $request->columns(), $rows, []);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return 3;
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

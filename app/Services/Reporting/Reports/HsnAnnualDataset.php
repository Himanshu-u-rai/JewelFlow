<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\HsnSummaryService;
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
 * HSN summary (GSTR-1 table 12) for the chosen period — pick a financial year with the FY presets, or a month.
 * Net of credit notes issued in the period. Wraps HsnSummaryService, so a year is the sum of its months.
 * Quick bills are not included (owner, 2026-11-16: handled separately).
 */
class HsnAnnualDataset extends ReportDatasetService
{
    public const KEY = 'gst-hsn-annual';
    public const VERSION = 'gst-hsn-annual@1';

    private const TOTALLED = ['quantity', 'taxable', 'igst', 'cgst', 'sgst', 'cess', 'total_value'];

    public function __construct(private readonly HsnSummaryService $hsn)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'HSN summary',
            classification: Cls::Compliance,
            columns: [
                Col::mandatory('hsn_code', 'HSN', T::String),
                Col::mandatory('uqc', 'UQC', T::String),
                Col::mandatory('quantity', 'Total Quantity', T::Decimal),
                Col::mandatory('taxable', 'Taxable Value', T::Money),
                Col::mandatory('igst', 'IGST', T::Money),
                Col::mandatory('cgst', 'CGST', T::Money),
                Col::mandatory('sgst', 'SGST', T::Money),
                Col::mandatory('cess', 'Cess', T::Money),
                Col::mandatory('total_value', 'Total Value', T::Money),
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
        $rows = $this->hsn->summary($request->shopId, $this->period($request));

        $totals = array_fill_keys(self::TOTALLED, 0.0);
        foreach ($rows as $row) {
            foreach (self::TOTALLED as $key) {
                $totals[$key] += $row[$key];
            }
        }
        $totals = array_map(static fn ($v) => round($v, 3), $totals);

        $hsn = new ReportSection('hsn', 'HSN summary', $this->cols($def, ['hsn_code', 'uqc', 'quantity', 'taxable', 'igst', 'cgst', 'sgst', 'cess', 'total_value']), $rows, $totals);

        $notes = [
            'Net of credit notes issued in the period: returned quantity and value are subtracted from the HSN they were sold under.',
            'Quantity is in grams (UQC GMS). Taxable value is the invoice line value; IGST applies to inter-state bills, CGST and SGST to the rest.',
            'Cess is not recorded in JewelFlow, so it is shown as 0.00.',
            'Quick bills are not included in this report.',
            'Lines without an HSN code are grouped under "—".',
        ];
        $basis = new ReportSection('basis', 'Basis of this report', $this->cols($def, ['note']), array_map(static fn ($n) => ['note' => $n], $notes));

        return new ReportDataset([$hsn, $basis], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return count($this->hsn->summary($request->shopId, $this->period($request)));
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

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
 * Credit Note Register — every credit note (return) issued in a period
 * (COMPLIANCE).
 *
 * Wraps TaxService::creditNoteRegister() verbatim. The taxable / total_gst /
 * total grand totals are summed from the rows and equal the DTO's own scalars
 * (totalTaxable, totalGst, totalValue) by construction — no re-query.
 *
 * Rigid (frozen §9): single Fixed profile, every column mandatory.
 */
class CreditNoteRegisterDataset extends ReportDatasetService
{
    public const KEY = 'cn-register';
    public const VERSION = 'cn-register@1';

    /** Numeric columns that carry a grand total. */
    private const TOTALLED = ['taxable', 'cgst', 'sgst', 'igst', 'total_gst', 'total'];

    public function __construct(private readonly TaxService $tax)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Credit Note Register',
            classification: Cls::Compliance,
            columns: [
                Col::mandatory('cn_no', 'Credit Note No', T::String),
                Col::mandatory('date', 'Date', T::Date),
                Col::mandatory('cn_type', 'Type', T::String),
                Col::mandatory('original_invoice', 'Original Invoice', T::String),
                Col::mandatory('customer', 'Customer', T::String),
                Col::mandatory('rate', 'GST Rate', T::String),
                Col::mandatory('taxable', 'Taxable Value', T::Money),
                Col::mandatory('cgst', 'CGST', T::Money),
                Col::mandatory('sgst', 'SGST', T::Money),
                Col::mandatory('igst', 'IGST', T::Money),
                Col::mandatory('total_gst', 'Total GST', T::Money),
                Col::mandatory('total', 'Total', T::Money),
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
        $data = $this->tax->creditNoteRegister($request->shopId, $this->period($request));

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'cn_no' => (string) $r->credit_note_number,
                'date' => $r->issued_at,
                'cn_type' => (string) $r->cn_type,
                'original_invoice' => $r->original_invoice_number,
                'customer' => $r->customer_name,
                'rate' => (string) $r->gst_rate . '%',
                'taxable' => (float) $r->taxable,
                'cgst' => (float) $r->cgst,
                'sgst' => (float) $r->sgst,
                'igst' => (float) $r->igst,
                'total_gst' => (float) $r->gst,
                'total' => (float) $r->total,
            ];
        }

        $totals = array_fill_keys(self::TOTALLED, 0.0);
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
        }
        $totals = array_map(static fn ($v) => round($v, 2), $totals);

        $section = new ReportSection('cn_register', 'Credit Note Register', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->tax->creditNoteRegister($request->shopId, $this->period($request))->count;
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

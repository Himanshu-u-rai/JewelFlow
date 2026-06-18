<?php

namespace App\Services\Reporting\Reports;

use App\Models\StockPurchase;
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
 * Stock Purchases — supplier purchase invoices (what stock/metal came in, from
 * whom, when, at what cost + GST breakdown). Accounting data export, period
 * filtered on purchase_date. Header-level only (line detail is a separate
 * future export).
 */
class StockPurchasesDataset extends ReportDatasetService
{
    public const KEY = 'stock-purchases';
    public const VERSION = 'stock-purchases@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Stock Purchases',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('purchase_number', 'Purchase No.', T::String),
                Col::mandatory('purchase_date', 'Purchase Date', T::Date),
                Col::mandatory('supplier_name', 'Supplier', T::String),
                Col::optional('supplier_gstin', 'Supplier GSTIN', T::String),
                Col::optional('invoice_number', 'Supplier Invoice', T::String),
                Col::optional('invoice_date', 'Invoice Date', T::Date),
                Col::mandatory('status', 'Status', T::String),
                Col::optional('subtotal_amount', 'Subtotal', T::Money),
                Col::optional('cgst_amount', 'CGST', T::Money),
                Col::optional('sgst_amount', 'SGST', T::Money),
                Col::optional('igst_amount', 'IGST', T::Money),
                Col::optional('tcs_amount', 'TCS', T::Money),
                Col::mandatory('total_amount', 'Total', T::Money),
                Col::optional('created_at', 'Recorded', T::DateTime),
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
        $purchases = $this->query($request)->get();

        $rows = [];
        $total = 0.0;
        foreach ($purchases as $sp) {
            $rows[] = [
                'purchase_number' => $sp->purchase_number,
                'purchase_date' => $sp->purchase_date,
                'supplier_name' => $sp->supplier_name ?? ($sp->vendor?->name ?? '—'),
                'supplier_gstin' => $sp->supplier_gstin,
                'invoice_number' => $sp->invoice_number,
                'invoice_date' => $sp->invoice_date,
                'status' => ucfirst(str_replace('_', ' ', (string) $sp->status)),
                'subtotal_amount' => (float) $sp->subtotal_amount,
                'cgst_amount' => (float) $sp->cgst_amount,
                'sgst_amount' => (float) $sp->sgst_amount,
                'igst_amount' => (float) $sp->igst_amount,
                'tcs_amount' => (float) $sp->tcs_amount,
                'total_amount' => (float) $sp->total_amount,
                'created_at' => $sp->created_at,
            ];
            $total += (float) $sp->total_amount;
        }

        $keys = $request->columnKeys;
        $totals = in_array('total_amount', $keys, true) ? ['total_amount' => round($total, 2)] : [];

        $section = new ReportSection('purchases', 'Stock Purchases', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = StockPurchase::query()->with('vendor');

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('purchase_date', [$period['from'], $period['to']]);
        }

        return $q->orderBy('purchase_date')->orderBy('id');
    }
}

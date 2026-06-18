<?php

namespace App\Services\Reporting\Reports;

use App\Models\Item;
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
 * Inventory Items — every individual physical piece (barcode, weight, purity,
 * charges, status), with its vendor and template design. Operational data
 * export. Filterable by status (in_stock / sold / …) and metal type. Distinct
 * from Products, which are the design templates.
 */
class InventoryItemsDataset extends ReportDatasetService
{
    public const KEY = 'inventory-items';
    public const VERSION = 'inventory-items@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Inventory Items',
            classification: Cls::Operational,
            columns: [
                Col::mandatory('barcode', 'Barcode', T::String),
                Col::mandatory('design', 'Design', T::String),
                Col::mandatory('status', 'Status', T::String),
                Col::optional('category', 'Category', T::String),
                Col::optional('sub_category', 'Sub Category', T::String),
                Col::optional('metal_type', 'Metal', T::String),
                Col::optional('purity', 'Purity', T::Decimal),
                Col::mandatory('gross_weight', 'Gross Wt (g)', T::Weight),
                Col::optional('stone_weight', 'Stone Wt (g)', T::Weight),
                Col::optional('net_metal_weight', 'Net Metal Wt (g)', T::Weight),
                Col::optional('wastage', 'Wastage (g)', T::Weight),
                Col::optional('making_charges', 'Making', T::Money),
                Col::optional('stone_charges', 'Stone', T::Money),
                Col::optional('cost_price', 'Cost Price', T::Money),
                Col::optional('selling_price', 'Selling Price', T::Money),
                Col::optional('source', 'Source', T::String),
                Col::optional('vendor', 'Vendor', T::String),
                Col::optional('huid', 'HUID', T::String),
                Col::optional('hallmark_date', 'Hallmark Date', T::Date),
                Col::optional('design_code', 'Template Code', T::String),
                Col::optional('created_at', 'Created', T::DateTime),
            ],
            profiles: [P::Summary, P::Detailed, P::Raw],
            filters: [
                Filter::for(FK::Status),
                Filter::for(FK::MetalType),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $items = $this->query($request)->with(['vendor', 'product'])->get();

        $rows = [];
        $grossTotal = 0.0;
        foreach ($items as $i) {
            $rows[] = [
                'barcode' => $i->barcode,
                'design' => $i->design,
                'status' => ucfirst(str_replace('_', ' ', (string) $i->status)),
                'category' => $i->category,
                'sub_category' => $i->sub_category,
                'metal_type' => $i->metal_type,
                'purity' => $i->purity !== null ? (float) $i->purity : null,
                'gross_weight' => (float) $i->gross_weight,
                'stone_weight' => (float) $i->stone_weight,
                'net_metal_weight' => (float) $i->net_metal_weight,
                'wastage' => (float) $i->wastage,
                'making_charges' => (float) $i->making_charges,
                'stone_charges' => (float) $i->stone_charges,
                'cost_price' => (float) $i->cost_price,
                'selling_price' => (float) $i->selling_price,
                'source' => $i->source,
                'vendor' => $i->vendor?->name ?? '—',
                'huid' => $i->huid ?? '—',
                'hallmark_date' => $i->hallmark_date,
                'design_code' => $i->product?->design_code ?? '—',
                'created_at' => $i->created_at,
            ];
            $grossTotal += (float) $i->gross_weight;
        }

        $keys = $request->columnKeys;
        $totals = in_array('gross_weight', $keys, true) ? ['gross_weight' => round($grossTotal, 3)] : [];

        $section = new ReportSection('items', 'Inventory Items', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = Item::query();

        if ($status = $request->filter('status')) {
            $q->where('status', $status);
        }
        if ($metal = $request->filter('metal_type')) {
            $q->where('metal_type', $metal);
        }

        return $q->orderBy('barcode')->orderBy('id');
    }
}

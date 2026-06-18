<?php

namespace App\Services\Reporting\Reports;

use App\Models\Product;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;

/**
 * Products — the design/catalog master (template designs: design code, default
 * purity/weight/making/stone). Operational data export. Distinct from Inventory
 * Items, which are the individual physical pieces. No sensitive columns.
 */
class ProductsDataset extends ReportDatasetService
{
    public const KEY = 'products';
    public const VERSION = 'products@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Products (Catalog)',
            classification: Cls::Operational,
            columns: [
                Col::mandatory('design_code', 'Design Code', T::String),
                Col::mandatory('name', 'Name', T::String),
                Col::mandatory('category', 'Category', T::String),
                Col::optional('sub_category', 'Sub Category', T::String),
                Col::optional('default_purity', 'Default Purity', T::Decimal),
                Col::optional('approx_weight', 'Approx Weight (g)', T::Weight),
                Col::optional('default_making', 'Default Making', T::Money),
                Col::optional('default_stone', 'Default Stone', T::Money),
                Col::optional('notes', 'Notes', T::String),
                Col::optional('has_image', 'Has Image', T::Boolean),
                Col::optional('created_at', 'Created', T::DateTime),
            ],
            profiles: [P::Summary, P::Detailed, P::Raw],
            filters: [],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $products = Product::query()
            ->with(['category', 'subCategory'])
            ->orderBy('design_code')->orderBy('id')->get();

        $rows = [];
        foreach ($products as $p) {
            $rows[] = [
                'design_code' => $p->design_code,
                'name' => $p->name,
                'category' => $p->category?->name ?? '—',
                'sub_category' => $p->subCategory?->name ?? '—',
                'default_purity' => $p->default_purity !== null ? (float) $p->default_purity : null,
                'approx_weight' => (float) $p->approx_weight,
                'default_making' => (float) $p->default_making,
                'default_stone' => (float) $p->default_stone,
                'notes' => $p->notes,
                'has_image' => ! empty($p->image),
                'created_at' => $p->created_at,
            ];
        }

        $section = new ReportSection('products', 'Products (Catalog)', $request->columns(), $rows, []);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return Product::query()->count();
    }
}

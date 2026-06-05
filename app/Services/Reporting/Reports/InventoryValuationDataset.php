<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\InventoryService;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition;
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
 * Inventory Valuation — on-hand stock valued at cost (Accounting, frozen §22).
 *
 * Wraps the canonical InventoryService::valuation() VERBATIM — the report layer
 * never re-values inventory. Reconciles BY CONSTRUCTION: each bucket section's
 * Σ cost_value equals the service `totalAtCost`, which equals
 * Σ items.cost_price (status=in_stock). Cost is a confidential dimension
 * (frozen §19/§22), so the document is watermarked CONFIDENTIAL and the margin
 * column is sensitive (permission-gated).
 */
class InventoryValuationDataset extends ReportDatasetService
{
    public const KEY = 'inventory-valuation';
    public const VERSION = 'inventory-valuation@1';

    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Inventory Valuation',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('category', 'Category', T::String),
                Col::mandatory('metal', 'Metal', T::String),
                Col::mandatory('items', 'Items', T::Integer),
                Col::mandatory('cost_value', 'Cost Value', T::Money),
                Col::optional('fine_weight', 'Fine Wt (g)', T::Weight),
                Col::optional('retail_value', 'Tag Value', T::Money),
                Col::sensitive('margin', 'Margin', T::Money),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [
                Filter::for(FK::AsOf),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
            watermarkBaseline: 'CONFIDENTIAL',
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $keys = $request->columnKeys;
        $data = $this->inventory->valuation($request->shopId);

        // --- By category ------------------------------------------------
        $catCols = $this->cols($def, $this->keep(['category', 'items', 'cost_value', 'retail_value', 'margin'], $keys));
        $catRows = [];
        foreach ($data->byCategory as $r) {
            $catRows[] = [
                'category' => (string) $r->category,
                'items' => (int) $r->count,
                'cost_value' => (float) $r->cost_value,
                'retail_value' => (float) $r->retail_value,
                'margin' => round((float) $r->retail_value - (float) $r->cost_value, 2),
            ];
        }
        $byCategory = new ReportSection('by_category', 'Valuation by Category', $catCols, $catRows, $this->sum($catRows, $catCols));

        // --- By metal ---------------------------------------------------
        $metalCols = $this->cols($def, $this->keep(['metal', 'items', 'fine_weight', 'cost_value'], $keys));
        $metalRows = [];
        foreach ($data->byMetal as $r) {
            $metalRows[] = [
                'metal' => (string) $r->metal_type,
                'items' => (int) $r->count,
                'fine_weight' => (float) $r->fine_weight,
                'cost_value' => (float) $r->cost_value,
            ];
        }
        $byMetal = new ReportSection('by_metal', 'Valuation by Metal', $metalCols, $metalRows, $this->sum($metalRows, $metalCols));

        return new ReportDataset([$byCategory, $byMetal], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        $data = $this->inventory->valuation($request->shopId);

        return $data->byCategory->count() + $data->byMetal->count();
    }

    /**
     * @param  string[]  $candidate
     * @param  string[]  $allowed
     * @return string[]
     */
    private function keep(array $candidate, array $allowed): array
    {
        return array_values(array_filter($candidate, static fn ($k) => in_array($k, $allowed, true)));
    }

    /**
     * @param  string[]  $keys
     * @return ColumnDefinition[]
     */
    private function cols(ReportDefinition $def, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $column = $def->column($key);
            if ($column !== null) {
                $out[] = $column;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  ColumnDefinition[]  $cols
     * @return array<string, float|int>
     */
    private function sum(array $rows, array $cols): array
    {
        $totals = [];
        foreach ($cols as $col) {
            if (! $col->type->isNumeric()) {
                continue;
            }
            $sum = 0.0;
            foreach ($rows as $row) {
                $sum += (float) ($row[$col->key] ?? 0);
            }
            $totals[$col->key] = round($sum, $col->type === T::Weight ? 4 : 2);
        }

        return $totals;
    }
}

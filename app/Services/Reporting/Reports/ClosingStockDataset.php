<?php

namespace App\Services\Reporting\Reports;

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
use App\Services\YearEndStock;

/**
 * Closing stock for a financial year (owner-approved 2026-11-16): every piece held on 31 March, valued at the lower
 * of acquisition cost and the closing rate (AS-2 / ICDS-IV). Source of truth is the latest year-end snapshot when
 * one exists, otherwise the same figures computed live (YearEndStock) — the document says which.
 *
 * Cost is a confidential dimension (frozen §19/§22): the whole report needs the money switch, the watermark is
 * CONFIDENTIAL, and the cost columns themselves are sensitive (opt-in). A piece with no recorded cost, or no rate
 * on or before year end, is shown but not valued — counted, never guessed.
 */
class ClosingStockDataset extends ReportDatasetService
{
    use StockYearReport;

    public const KEY = 'closing-stock';
    public const VERSION = 'closing-stock@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Closing Stock',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('barcode', 'Barcode', T::String),
                Col::mandatory('metal', 'Metal', T::String),
                Col::mandatory('purity', 'Purity', T::Decimal),
                Col::mandatory('items', 'Pieces', T::Integer),
                Col::mandatory('net_weight', 'Net Metal Wt (g)', T::Weight),
                Col::mandatory('valuation', 'Valuation (lower of cost and market)', T::Money),
                Col::mandatory('not_valued', 'Not Valued', T::Integer),
                Col::optional('design', 'Design', T::String),
                Col::optional('category', 'Category', T::String),
                Col::optional('gross_weight', 'Gross Wt (g)', T::Weight),
                Col::optional('closing_rate', 'Closing Rate / g', T::Money),
                Col::optional('rate_date', 'Rate Date', T::Date),
                Col::optional('value_at_rate', 'Value at Closing Rate', T::Money),
                Col::sensitive('acquisition_cost', 'Acquisition Cost', T::Money),
                Col::sensitive('cost_source', 'Cost Source', T::String),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [Filter::for(FK::Period, true)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default()->withView('reports.money'),
            watermarkBaseline: 'CONFIDENTIAL',
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $keys = $request->columnKeys;
        $fy = $this->yearOf($request);
        $stock = YearEndStock::forYear($request->shopId, $fy);

        $groups = [];
        $itemRows = [];
        foreach ($stock['rows'] as $r) {
            $metal = (string) ($r['metal_type'] ?? 'unknown');
            $g = &$groups[$metal . '|' . sprintf('%08.3f', (float) $r['purity'])];
            $g ??= ['metal' => $metal, 'purity' => $r['purity'], 'items' => 0, 'gross_weight' => 0.0, 'net_weight' => 0.0,
                'acquisition_cost' => 0.0, 'value_at_rate' => 0.0, 'valuation' => 0.0, 'not_valued' => 0];
            $g['items']++;
            $g['gross_weight'] += (float) $r['gross_weight'];
            $g['net_weight'] += (float) $r['net_metal_weight'];
            $g['acquisition_cost'] += (float) $r['acquisition_cost'];
            $g['value_at_rate'] += (float) $r['value_at_closing_rate'];
            $g['valuation'] += (float) $r['valuation'];
            if ($r['valuation'] === null) {
                $g['not_valued']++;
            }
            unset($g);

            $itemRows[] = [
                'barcode' => $r['barcode'], 'design' => $r['design'], 'category' => $r['category'],
                'metal' => $metal, 'purity' => $r['purity'],
                'gross_weight' => $r['gross_weight'], 'net_weight' => $r['net_metal_weight'],
                'acquisition_cost' => $r['acquisition_cost'], 'cost_source' => $r['acquisition_cost_source'],
                'closing_rate' => $r['closing_rate_per_gram'], 'rate_date' => $r['closing_rate_date'],
                'value_at_rate' => $r['value_at_closing_rate'], 'valuation' => $r['valuation'],
            ];
        }
        ksort($groups);

        $metalCols = $this->cols($def, $this->keep(
            ['metal', 'purity', 'items', 'gross_weight', 'net_weight', 'acquisition_cost', 'value_at_rate', 'valuation', 'not_valued'], $keys));
        $metalRows = array_map(fn ($g) => $this->shape($this->round($g), $metalCols), array_values($groups));
        $totals = [];
        foreach ($metalCols as $c) {
            if ($c->type->isNumeric() && $c->key !== 'purity') {
                $totals[$c->key] = round(array_sum(array_map(fn ($g) => (float) $g[$c->key], $groups)), $c->type === T::Weight ? 4 : 2);
                if ($c->type === T::Integer) {
                    $totals[$c->key] = (int) $totals[$c->key];
                }
            }
        }
        $byMetal = new ReportSection('by_metal', 'Closing Stock by Metal and Purity', $metalCols, $metalRows, $totals);

        $itemCols = $this->cols($def, $this->keep(
            ['barcode', 'design', 'category', 'metal', 'purity', 'gross_weight', 'net_weight', 'acquisition_cost', 'cost_source',
                'closing_rate', 'rate_date', 'value_at_rate', 'valuation'], $keys));
        $items = new ReportSection('items', 'Pieces in Stock', $itemCols, array_map(fn ($r) => $this->shape($r, $itemCols), $itemRows));

        $meta = $this->withNotes($meta, [
            'Financial year' => $fy->label(),
            'Stock as at' => $stock['as_at'],
            'Stock source' => $stock['source'],
        ] + $this->historyNote($request->shopId, [$stock['as_at']]));

        return new ReportDataset([$byMetal, $items], $meta);
    }

    /** @param array<string, mixed> $g */
    private function round(array $g): array
    {
        foreach (['gross_weight', 'net_weight'] as $k) {
            $g[$k] = round($g[$k], 4);
        }
        foreach (['acquisition_cost', 'value_at_rate', 'valuation'] as $k) {
            $g[$k] = round($g[$k], 2);
        }

        return $g;
    }
}

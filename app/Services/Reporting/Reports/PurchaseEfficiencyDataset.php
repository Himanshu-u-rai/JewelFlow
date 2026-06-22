<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\InventoryService;
use App\Reporting\ReportPeriod;
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
 * Purchase Efficiency — raw-metal purchase cost vs market over a period, per
 * metal, with the premium paid above market (Accounting; GAP 2 tail). Wraps
 * InventoryService::purchaseEfficiency() VERBATIM. Premium % is not additive
 * (never totalled). Reconciles BY CONSTRUCTION: Σ premium = totalPremium.
 */
class PurchaseEfficiencyDataset extends ReportDatasetService
{
    public const KEY = 'purchase-efficiency';
    public const VERSION = 'purchase-efficiency@1';

    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Purchase Efficiency',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('metal', 'Metal', T::String),
                Col::optional('lines', 'Lines', T::Integer),
                Col::optional('lines_no_market', 'No Market Rate', T::Integer),
                Col::mandatory('gross', 'Gross (g)', T::Weight),
                Col::mandatory('paid', 'Paid', T::Money),
                Col::mandatory('market', 'Market', T::Money),
                Col::mandatory('premium', 'Premium', T::Money),
                Col::optional('premium_pct', 'Premium %', T::Percent),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [Filter::for(FK::Period, true)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $data = $this->inventory->purchaseEfficiency($request->shopId, $this->period($request));

        $cols = $this->cols($def, $this->keep(
            ['metal', 'lines', 'lines_no_market', 'gross', 'paid', 'market', 'premium', 'premium_pct'],
            $request->columnKeys,
        ));

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'metal' => (string) $r->metal_type,
                'lines' => (int) $r->line_count,
                'lines_no_market' => (int) $r->lines_no_market,
                'gross' => (float) $r->total_gross,
                'paid' => (float) $r->purchase_cost,
                'market' => (float) $r->market_cost,
                'premium' => (float) $r->premium,
                'premium_pct' => (float) $r->premium_pct,
            ];
        }

        $section = new ReportSection('by_metal', 'By Metal', $cols, $rows, $this->sum($rows, $cols));

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->inventory->purchaseEfficiency($request->shopId, $this->period($request))->rows->count();
    }

    private function period(ReportRequest $request): ReportPeriod
    {
        $period = $request->filter('period', []);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        return ReportPeriod::range($from ? $from->toDateString() : null, $to ? $to->toDateString() : null);
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
            if (! $col->type->isNumeric() || $col->type === T::Percent) {
                continue;
            }
            $sum = 0.0;
            foreach ($rows as $row) {
                $sum += (float) ($row[$col->key] ?? 0);
            }
            $totals[$col->key] = round($sum, $col->type === T::Weight ? 3 : 2);
        }

        return $totals;
    }
}

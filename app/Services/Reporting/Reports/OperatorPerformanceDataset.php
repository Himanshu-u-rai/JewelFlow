<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\AuditService;
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
 * Operator Performance — per-operator sales / discount / returns / net sales over
 * a period (Audit class; GAP 2). Wraps the canonical
 * AuditService::operatorPerformance() VERBATIM — the report layer never
 * re-aggregates. Reconciles BY CONSTRUCTION: the section's Σ matches the
 * service's totalSales / totalDiscount / totalReturnsValue.
 *
 * Audit class → owner/manager surface gate (reports.audit), no CA Standard
 * profile (frozen §11/§18); the report is not customer-shareable.
 */
class OperatorPerformanceDataset extends ReportDatasetService
{
    public const KEY = 'operator-performance';
    public const VERSION = 'operator-performance@1';

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Operator Performance',
            classification: Cls::Audit,
            columns: [
                Col::mandatory('operator', 'Operator', T::String),
                Col::mandatory('invoices', 'Invoices', T::Integer),
                Col::mandatory('sales', 'Sales', T::Money),
                Col::optional('discount', 'Discount', T::Money),
                Col::optional('returns', 'Returns', T::Integer),
                Col::optional('returns_value', 'Returns Value', T::Money),
                Col::mandatory('net_sales', 'Net Sales', T::Money),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [Filter::for(FK::Period, true)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default()->withSurfaceGate('reports.audit'),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $data = $this->audit->operatorPerformance($request->shopId, $this->period($request));

        $cols = $this->cols($def, $this->keep(
            ['operator', 'invoices', 'sales', 'discount', 'returns', 'returns_value', 'net_sales'],
            $request->columnKeys,
        ));

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'operator' => (string) $r->operator_name,
                'invoices' => (int) $r->invoice_count,
                'sales' => (float) $r->total_sales,
                'discount' => (float) $r->total_discount,
                'returns' => (int) $r->returns_count,
                'returns_value' => (float) $r->returns_value,
                'net_sales' => (float) $r->net_sales,
            ];
        }

        $section = new ReportSection('operators', 'By Operator', $cols, $rows, $this->sum($rows, $cols));

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->audit->operatorPerformance($request->shopId, $this->period($request))->rows->count();
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
            if (! $col->type->isNumeric()) {
                continue;
            }
            $sum = 0.0;
            foreach ($rows as $row) {
                $sum += (float) ($row[$col->key] ?? 0);
            }
            $totals[$col->key] = round($sum, 2);
        }

        return $totals;
    }
}

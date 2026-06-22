<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\ReceivablesService;
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
use Carbon\Carbon;

/**
 * Customer Dues Aging — outstanding receivables bucketed by age as of a date
 * (Receivables; GAP 2 tail). Wraps ReceivablesService::duesAging() VERBATIM —
 * the report layer never re-ages dues. Reconciles BY CONSTRUCTION: the section's
 * Σ per bucket equals the service bucket totals; Σ total equals totalOutstanding.
 *
 * Customer mobile is PII → the `mobile` column is sensitive (permission-gated,
 * off in CA/external profiles).
 */
class DuesAgingDataset extends ReportDatasetService
{
    public const KEY = 'dues-aging';
    public const VERSION = 'dues-aging@1';

    public function __construct(private readonly ReceivablesService $receivables)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Customer Dues Aging',
            classification: Cls::Receivables,
            columns: [
                Col::mandatory('customer', 'Customer', T::String),
                Col::sensitive('mobile', 'Mobile', T::String),
                Col::optional('invoices', 'Invoices', T::Integer),
                Col::mandatory('current', 'Current (0–30)', T::Money),
                Col::mandatory('d3160', '31–60', T::Money),
                Col::mandatory('d6190', '61–90', T::Money),
                Col::mandatory('d90plus', '90+', T::Money),
                Col::mandatory('total', 'Total Outstanding', T::Money),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard],
            filters: [Filter::for(FK::AsOf)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $data = $this->receivables->duesAging($request->shopId, $this->asOf($request));

        $cols = $this->cols($def, $this->keep(
            ['customer', 'mobile', 'invoices', 'current', 'd3160', 'd6190', 'd90plus', 'total'],
            $request->columnKeys,
        ));

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'customer' => (string) $r->customer_name,
                'mobile' => (string) ($r->mobile ?? ''),
                'invoices' => (int) $r->invoice_count,
                'current' => (float) $r->current,
                'd3160' => (float) $r->d3160,
                'd6190' => (float) $r->d6190,
                'd90plus' => (float) $r->d90plus,
                'total' => (float) $r->total,
            ];
        }

        $section = new ReportSection('aging', 'By Customer', $cols, $rows, $this->sum($rows, $cols));

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->receivables->duesAging($request->shopId, $this->asOf($request))->rows->count();
    }

    /** AsOf reports use the period end as the point-in-time date. */
    private function asOf(ReportRequest $request): Carbon
    {
        $period = $request->filter('period', []);
        $to = $period['to'] ?? null;

        return $to ? Carbon::parse($to->toDateString()) : Carbon::now();
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

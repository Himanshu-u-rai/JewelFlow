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
use App\Support\ShopEdition;
use Carbon\Carbon;

/**
 * Pending EMI / Installment Visibility — open installment plans with paid /
 * remaining / next-due as of a date (Receivables, retailer edition; GAP 2 tail).
 * Wraps ReceivablesService::emiVisibility() VERBATIM — never re-computes a plan.
 */
class EmiDataset extends ReportDatasetService
{
    public const KEY = 'emi';
    public const VERSION = 'emi@1';

    public function __construct(private readonly ReceivablesService $receivables)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Pending EMI',
            classification: Cls::Receivables,
            columns: [
                Col::mandatory('customer', 'Customer', T::String),
                Col::optional('invoice', 'Invoice', T::String),
                Col::mandatory('total_payable', 'Total Payable', T::Money),
                Col::mandatory('paid', 'Paid', T::Money),
                Col::mandatory('remaining', 'Remaining', T::Money),
                Col::optional('emis', 'EMIs', T::String),
                Col::optional('next_due', 'Next Due', T::Date),
                Col::optional('overdue_days', 'Overdue Days', T::Integer),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard],
            filters: [Filter::for(FK::AsOf)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default()->withEdition(ShopEdition::RETAILER),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $data = $this->receivables->emiVisibility($request->shopId, $this->asOf($request));

        $cols = $this->cols($def, $this->keep(
            ['customer', 'invoice', 'total_payable', 'paid', 'remaining', 'emis', 'next_due', 'overdue_days'],
            $request->columnKeys,
        ));

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'customer' => (string) $r->customer_name,
                'invoice' => (string) ($r->invoice_number ?? ''),
                'total_payable' => (float) $r->total_payable,
                'paid' => (float) $r->paid,
                'remaining' => (float) $r->remaining,
                'emis' => $r->emis_paid . '/' . $r->total_emis,
                'next_due' => $r->next_due_date ? Carbon::parse($r->next_due_date)->toDateString() : '',
                'overdue_days' => $r->overdue ? (int) $r->days_overdue : 0,
            ];
        }

        $section = new ReportSection('plans', 'Open Plans', $cols, $rows, $this->sum($rows, $cols));

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->receivables->emiVisibility($request->shopId, $this->asOf($request))->rows->count();
    }

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
            if (! $col->type->isNumeric() || $col->key === 'overdue_days') {
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

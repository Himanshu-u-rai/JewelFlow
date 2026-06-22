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
 * Scheme Liability Exposure — per-enrollment savings-scheme balances the shop
 * owes customers (Receivables, retailer edition; GAP 2 tail). Wraps
 * ReceivablesService::schemeLiability() VERBATIM. Point-in-time (now), so no
 * period/as-of filter. Reconciles BY CONSTRUCTION: Σ current_balance equals the
 * service totalLiability.
 */
class SchemeLiabilityDataset extends ReportDatasetService
{
    public const KEY = 'scheme-liability';
    public const VERSION = 'scheme-liability@1';

    public function __construct(private readonly ReceivablesService $receivables)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Scheme Liability',
            classification: Cls::Receivables,
            columns: [
                Col::mandatory('customer', 'Customer', T::String),
                Col::mandatory('scheme', 'Scheme', T::String),
                Col::optional('status', 'Status', T::String),
                Col::mandatory('contributed', 'Contributed', T::Money),
                Col::optional('bonus', 'Bonus Accrued', T::Money),
                Col::mandatory('balance', 'Current Balance', T::Money),
                Col::optional('maturity', 'Maturity Date', T::Date),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard],
            filters: [],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default()->withEdition(ShopEdition::RETAILER),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $data = $this->receivables->schemeLiability($request->shopId);

        $cols = $this->cols($def, $this->keep(
            ['customer', 'scheme', 'status', 'contributed', 'bonus', 'balance', 'maturity'],
            $request->columnKeys,
        ));

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'customer' => (string) $r->customer_name,
                'scheme' => (string) ($r->scheme_name ?? ''),
                'status' => (string) $r->status,
                'contributed' => (float) $r->total_paid,
                'bonus' => (float) $r->bonus_accrued,
                'balance' => (float) $r->current_balance,
                'maturity' => $r->maturity_date ? Carbon::parse($r->maturity_date)->toDateString() : '',
            ];
        }

        $section = new ReportSection('schemes', 'By Enrollment', $cols, $rows, $this->sum($rows, $cols));

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->receivables->schemeLiability($request->shopId)->rows->count();
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

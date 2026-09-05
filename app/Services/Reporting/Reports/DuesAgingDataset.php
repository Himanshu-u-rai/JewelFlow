<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\Data\DuesAgingData;
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
 * (Receivables; GAP 2 tail). Wraps ReceivablesService::duesAging() /
 * ::historicalDuesAging() VERBATIM — the report layer never re-ages dues.
 * Reconciles BY CONSTRUCTION: the section's Σ per bucket equals the service
 * bucket totals; Σ total equals totalOutstanding.
 *
 * `sales_source` (Batch 4 §6 steps 2/4, §9.7.2) selects LIVE (default — byte-
 * identical to before this filter existed, §5 test 1) or HISTORICAL. Any
 * other/unrecognised value (including `combined`) falls back to LIVE: Combined
 * mode is deliberately NOT implemented — §7.5 (schema can't express a partial
 * overlap) and §7.6 (no approved document says Combined may read
 * CustomerOpeningBalance at all) are unresolved owner decisions, not guessed
 * here. `combined` is not offered as an option anywhere a control is rendered
 * (see `FilterControlResolver`) — reaching this fallback requires a
 * hand-crafted query string.
 *
 * HISTORICAL mode's §4 dedup exclusions (already-in-opening-balance /
 * unresolved-overlap / unknown-outstanding) are never silently dropped — they
 * render as an extra "Notes — Historical Mode" section, same disclosure
 * pattern as `HistoricalSalesRegisterDataset::notesSection()`.
 *
 * Customer mobile is PII → the `mobile` column is sensitive (permission-gated,
 * off in CA/external profiles).
 */
class DuesAgingDataset extends ReportDatasetService
{
    public const KEY = 'dues-aging';
    public const VERSION = 'dues-aging@1';

    private const HISTORICAL = 'historical';

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
                // Notes-section-only columns (HISTORICAL mode disclosure) — one
                // shared catalogue filtered per section, same pattern as
                // HistoricalSalesRegisterDataset's metric/detail pair.
                Col::mandatory('metric', 'Particular', T::String),
                Col::mandatory('detail', 'Detail', T::String),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard],
            filters: [Filter::for(FK::AsOf), Filter::for(FK::SalesSource)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $data = $this->data($request);

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

        $sections = [new ReportSection('aging', 'By Customer', $cols, $rows, $this->sum($rows, $cols))];

        $notes = $this->historicalNotesSection($def, $data);
        if ($notes !== null) {
            $sections[] = $notes;
        }

        return new ReportDataset($sections, $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->data($request)->rows->count();
    }

    /** Resolves `sales_source` — anything but exactly `historical` is LIVE (§7.5/§7.6 keep Combined blocked). */
    private function data(ReportRequest $request): DuesAgingData
    {
        $mode = (string) $request->filter('sales_source', 'live');
        $asOf = $this->asOf($request);

        return $mode === self::HISTORICAL
            ? $this->receivables->historicalDuesAging($request->shopId, $asOf)
            : $this->receivables->duesAging($request->shopId, $asOf);
    }

    /**
     * "Notes — Historical Mode" — same disclosure pattern as
     * HistoricalSalesRegisterDataset::notesSection(): every §4 dedup
     * exclusion and every unknown-outstanding document gets a visible line,
     * never a silent drop. Absent entirely in LIVE mode (all 3 counts are
     * always 0 there — see DuesAgingData's doc comment).
     */
    private function historicalNotesSection(ReportDefinition $def, DuesAgingData $data): ?ReportSection
    {
        $included = $data->excludedIncludedInOpeningBalanceCount;
        $unresolved = $data->excludedUnresolvedOverlapCount;
        $unknown = $data->excludedUnknownOutstandingCount;

        if ($included === 0 && $unresolved === 0 && $unknown === 0) {
            return null;
        }

        $rows = [];
        if ($included > 0) {
            $rows[] = [
                'metric' => 'Already in opening balance',
                'detail' => "{$included} document(s) excluded — already counted in the customer's opening balance elsewhere; adding them here would double the debt.",
            ];
        }
        if ($unresolved > 0) {
            $rows[] = [
                'metric' => 'Unresolved overlap',
                'detail' => "{$unresolved} document(s) flagged as possibly overlapping opening balance but never resolved by an operator — excluded pending review, never guessed.",
            ];
        }
        if ($unknown > 0) {
            $rows[] = [
                'metric' => 'Unknown outstanding',
                'detail' => "{$unknown} document(s) have no recorded outstanding amount (shown blank at import, not ₹0) — excluded from every total above.",
            ];
        }

        return new ReportSection('aging_historical_notes', 'Notes — Historical Mode', $this->cols($def, ['metric', 'detail']), $rows, []);
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

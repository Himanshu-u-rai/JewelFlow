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
use Illuminate\Support\Collection;

/**
 * Customer Dues Aging — outstanding receivables bucketed by age as of a date
 * (Receivables; GAP 2 tail). Wraps `ReceivablesService::duesAging()` /
 * `::historicalDuesAging()` VERBATIM — the report layer never re-ages dues.
 *
 * `sales_source` selects one of three modes (owner-agreed reporting
 * semantics — supersedes the earlier "combined falls back to live" design):
 *
 * 1. **LIVE** (default) — unchanged, byte-identical to before this filter
 *    existed. Reads only `invoices`/`invoice_payments`.
 * 2. **HISTORICAL** — published `historical_sales_documents`, labelled
 *    "Unpaid as Recorded" (never "current outstanding" — nothing here has
 *    been re-verified since publish). Age is labelled "days since invoice
 *    date", never "days overdue" — neither LIVE nor HISTORICAL has a real
 *    due date to be overdue against. An `opening_balance_overlap` flag is
 *    **never grounds to remove an amount** — this mode reads no opening
 *    balance, so there is nothing to double-count against; every
 *    classification (`included_in_opening_balance` /
 *    `separate_from_opening_balance` / unresolved) is counted in full and
 *    merely disclosed as a warning in the Notes section. A NULL
 *    `outstanding_amount_snapshot` is unknown, not zero — excluded from the
 *    sum (the one genuine exclusion) and disclosed so the total reads as
 *    incomplete, not final. Snapshot-only/unlinked customers get their own
 *    section under their recorded identity — never merged with, or guessed
 *    onto, a linked customer.
 * 3. **COMBINED** — Live and Historical shown SIDE BY SIDE as two
 *    independent sections, each with its own subtotal. There is no monetary
 *    grand total combining the two, and no `CustomerOpeningBalance` read at
 *    all — numeric reconciliation between Live, Historical, and opening
 *    balance remains explicitly deferred (owner decisions §7.5/§7.6 are
 *    unresolved as to *that* question and do not block this presentation,
 *    which reconciles nothing). A Notes section always explains this.
 *
 * Any value other than these three falls back to LIVE as a defensive-depth
 * default inside this class — the real input-handling gate is upstream
 * (`ExportRequest` validation / `ReportScreenController`'s inline validation
 * per `sales_source`), which rejects an explicit unsupported value outright
 * rather than letting it reach here at all (see `FilterControlResolver` for
 * the offered options).
 *
 * Customer mobile is PII → the `mobile` column is sensitive (permission-gated,
 * off in CA/external profiles).
 */
class DuesAgingDataset extends ReportDatasetService
{
    public const KEY = 'dues-aging';

    public const VERSION = 'dues-aging@1';

    private const LIVE = 'live';

    private const HISTORICAL = 'historical';

    private const COMBINED = 'combined';

    /** Historical/Combined-historical column relabels — HISTORICAL_LABELS[$key] overrides the LIVE catalogue label. Everything not listed keeps the catalogue label unchanged. */
    private const HISTORICAL_LABELS = [
        'invoices' => 'Documents',
        'current' => '0–30 days since invoice date',
        'd3160' => '31–60 days since invoice date',
        'd6190' => '61–90 days since invoice date',
        'd90plus' => '90+ days since invoice date',
        'total' => 'Unpaid as Recorded',
    ];

    public function __construct(private readonly ReceivablesService $receivables) {}

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
                // Notes-section-only columns (HISTORICAL/COMBINED disclosure)
                // — one shared catalogue filtered per section, same pattern
                // as HistoricalSalesRegisterDataset's metric/detail pair.
                Col::mandatory('metric', 'Particular', T::String),
                Col::mandatory('detail', 'Detail', T::String),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard],
            filters: [Filter::for(FK::AsOf), Filter::for(FK::SalesSource)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            // LIVE keeps its pre-Batch-4 gate (reports.view/export) unchanged;
            // HISTORICAL/COMBINED additionally require historical.view — the
            // same class of data Historical Sales Register already gates
            // behind it (see ReportPermissions::gateForSalesSource()).
            permissions: Perm::default()->withHistoricalModeGate('historical.view'),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $asOf = $this->asOf($request);

        return match ($this->mode($request)) {
            self::HISTORICAL => $this->buildHistorical($def, $request, $asOf, $meta),
            self::COMBINED => $this->buildCombined($def, $request, $asOf, $meta),
            default => $this->buildLive($def, $request, $asOf, $meta),
        };
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        $asOf = $this->asOf($request);

        return match ($this->mode($request)) {
            self::HISTORICAL => (function () use ($request, $asOf) {
                $data = $this->receivables->historicalDuesAging($request->shopId, $asOf);

                return $data->rows->count() + $data->unlinkedRows->count();
            })(),
            self::COMBINED => (function () use ($request, $asOf) {
                $live = $this->receivables->duesAging($request->shopId, $asOf);
                $historical = $this->receivables->historicalDuesAging($request->shopId, $asOf);

                return $live->rows->count() + $historical->rows->count() + $historical->unlinkedRows->count();
            })(),
            default => $this->receivables->duesAging($request->shopId, $asOf)->rows->count(),
        };
    }

    /** LIVE — unchanged, single "By Customer" section, byte-identical to before this filter existed. */
    private function buildLive(ReportDefinition $def, ReportRequest $request, Carbon $asOf, ReportMeta $meta): ReportDataset
    {
        $data = $this->receivables->duesAging($request->shopId, $asOf);
        $cols = $this->agingColumns($def, $request->columnKeys, historical: false);
        $rows = $this->toRows($data->rows);

        return new ReportDataset([
            new ReportSection('aging', 'By Customer', $cols, $rows, $this->sum($rows, $cols)),
        ], $meta);
    }

    /** HISTORICAL — "Unpaid as Recorded", overlap classifications retained and disclosed, unlinked customers in their own section. */
    private function buildHistorical(ReportDefinition $def, ReportRequest $request, Carbon $asOf, ReportMeta $meta): ReportDataset
    {
        $data = $this->receivables->historicalDuesAging($request->shopId, $asOf);
        $cols = $this->agingColumns($def, $request->columnKeys, historical: true);
        $rows = $this->toRows($data->rows);

        $sections = [new ReportSection('aging', 'Historical — Unpaid as Recorded', $cols, $rows, $this->sum($rows, $cols))];

        $unlinked = $this->unlinkedSection($cols, $data);
        if ($unlinked !== null) {
            $sections[] = $unlinked;
        }

        $notes = $this->notesSection($def, $data, combinedExplanation: false);
        if ($notes !== null) {
            $sections[] = $notes;
        }

        return new ReportDataset($sections, $meta);
    }

    /**
     * COMBINED — Live and Historical side by side, each its own section with
     * its own subtotal. Deliberately no section (or anything else) sums the
     * two into one grand total, and no `CustomerOpeningBalance` read is
     * involved anywhere in this method.
     */
    private function buildCombined(ReportDefinition $def, ReportRequest $request, Carbon $asOf, ReportMeta $meta): ReportDataset
    {
        $live = $this->receivables->duesAging($request->shopId, $asOf);
        $historical = $this->receivables->historicalDuesAging($request->shopId, $asOf);

        $liveCols = $this->agingColumns($def, $request->columnKeys, historical: false);
        $histCols = $this->agingColumns($def, $request->columnKeys, historical: true);

        $liveRows = $this->toRows($live->rows);
        $histRows = $this->toRows($historical->rows);

        $sections = [
            new ReportSection('aging_live', 'Live — By Customer', $liveCols, $liveRows, $this->sum($liveRows, $liveCols)),
            new ReportSection('aging_historical', 'Historical — Unpaid as Recorded', $histCols, $histRows, $this->sum($histRows, $histCols)),
        ];

        $unlinked = $this->unlinkedSection($histCols, $historical);
        if ($unlinked !== null) {
            $sections[] = $unlinked;
        }

        $notes = $this->notesSection($def, $historical, combinedExplanation: true);
        if ($notes !== null) {
            $sections[] = $notes;
        }

        return new ReportDataset($sections, $meta);
    }

    /** Snapshot-only/unlinked customers (`customer_id IS NULL`), shown under their recorded identity — never merged into the linked-customer section. */
    private function unlinkedSection(array $cols, DuesAgingData $data): ?ReportSection
    {
        if ($data->unlinkedRows->isEmpty()) {
            return null;
        }

        $rows = $this->toRows($data->unlinkedRows);

        return new ReportSection('aging_unlinked', 'Unlinked / Snapshot-only Customers — Unpaid as Recorded', $cols, $rows, $this->sum($rows, $cols));
    }

    /**
     * Disclosure notes — every overlap classification is a WARNING, never a
     * subtraction (owner-agreed semantics; see class docblock). In COMBINED
     * mode an explanatory row is always present; in HISTORICAL-only mode the
     * section is entirely absent when there is nothing to disclose (no
     * overlap flags anywhere, no unknown-outstanding documents).
     */
    private function notesSection(ReportDefinition $def, DuesAgingData $data, bool $combinedExplanation): ?ReportSection
    {
        $rows = [];

        if ($combinedExplanation) {
            $rows[] = [
                'metric' => 'Combined presentation',
                'detail' => 'Live and Historical are shown side-by-side with separate subtotals — never summed into one grand total, and neither reads CustomerOpeningBalance. Historical snapshots do not track any collection made after publish and may overlap amounts already posted to a customer\'s opening balance; reconciling the two numerically is deliberately deferred, not attempted here.',
            ];
        }

        if ($data->includedInOpeningBalanceCount > 0) {
            $rows[] = [
                'metric' => 'May be in opening balance',
                'detail' => "{$data->includedInOpeningBalanceCount} document(s) are flagged as already reflected in the customer's opening balance elsewhere. Shown here at full value regardless — this report does not read or net against opening balance.",
            ];
        }
        if ($data->unresolvedOverlapCount > 0) {
            $rows[] = [
                'metric' => 'Unresolved overlap',
                'detail' => "{$data->unresolvedOverlapCount} document(s) are flagged as possibly overlapping the opening balance, but an operator never resolved which way — shown here at full value; the classification is disclosed, never guessed.",
            ];
        }
        if ($data->separateFromOpeningBalanceCount > 0) {
            $rows[] = [
                'metric' => 'Confirmed separate from opening balance',
                'detail' => "{$data->separateFromOpeningBalanceCount} document(s) were confirmed by an operator as distinct debt, unrelated to the opening balance.",
            ];
        }
        if ($data->unknownOutstandingCount > 0) {
            $rows[] = [
                'metric' => 'Unknown outstanding — totals incomplete',
                'detail' => "{$data->unknownOutstandingCount} document(s) have no recorded outstanding amount (shown blank at import, not ₹0) — excluded from every total above, which is therefore incomplete, not final.",
            ];
        }

        if ($rows === []) {
            return null;
        }

        return new ReportSection(
            'aging_historical_notes',
            $combinedExplanation ? 'Notes — Combined Mode' : 'Notes — Historical Mode',
            $this->cols($def, ['metric', 'detail']),
            $rows,
            [],
        );
    }

    /** Resolves `sales_source` — anything unrecognised falls back to LIVE (defense in depth; the real gate is upstream input validation, see class docblock). */
    private function mode(ReportRequest $request): string
    {
        $mode = (string) $request->filter('sales_source', self::LIVE);

        return in_array($mode, [self::LIVE, self::HISTORICAL, self::COMBINED], true) ? $mode : self::LIVE;
    }

    /** AsOf reports use the period end as the point-in-time date. */
    private function asOf(ReportRequest $request): Carbon
    {
        $period = $request->filter('period', []);
        $to = $period['to'] ?? null;

        return $to ? Carbon::parse($to->toDateString()) : Carbon::now();
    }

    /** @return array<int, array<string, mixed>> */
    private function toRows(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
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

        return $out;
    }

    /**
     * Resolves the aging column set for the given mode. LIVE uses the
     * catalogue labels verbatim (byte-identical). HISTORICAL/COMBINED-
     * historical relabels a few columns per {@see self::HISTORICAL_LABELS}
     * ("Unpaid as Recorded", "days since invoice date") without touching the
     * catalogue itself — type/tier/masking are reused as declared, so
     * ColumnPolicy gating and masking are unaffected by the relabel.
     *
     * @param  string[]  $allowedKeys
     * @return ColumnDefinition[]
     */
    private function agingColumns(ReportDefinition $def, array $allowedKeys, bool $historical): array
    {
        $keys = $this->keep(
            ['customer', 'mobile', 'invoices', 'current', 'd3160', 'd6190', 'd90plus', 'total'],
            $allowedKeys,
        );

        if (! $historical) {
            return $this->cols($def, $keys);
        }

        $out = [];
        foreach ($keys as $key) {
            $column = $def->column($key);
            if ($column === null) {
                continue;
            }
            $out[] = new ColumnDefinition($key, self::HISTORICAL_LABELS[$key] ?? $column->label, $column->type, $column->tier, $column->masking);
        }

        return $out;
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

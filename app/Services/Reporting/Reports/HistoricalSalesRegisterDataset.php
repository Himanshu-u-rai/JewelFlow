<?php

namespace App\Services\Reporting\Reports;

use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument as Doc;
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
use App\Support\Historical\HistoricalDocumentIdentity;

/**
 * Historical Sales Register — a read-only list/search of pre-JewelFlow sales
 * evidence (Batch 4, `HISTORICAL-BATCH-4-REQUIREMENTS.md` §2/§3/§9.7.1).
 *
 * Deliberately the "zero open product decisions" slice of Batch 4: a flat
 * listing of `historical_sales_documents`, no aging, no dedup, no Combined
 * mode, no write path (read-only query, same posture as every other dataset
 * in this namespace). Defaults to `published` documents; an explicit status
 * filter widens the search to draft/void/superseded for audit trail (§3) —
 * only `published` rows ever contribute to the totals row (§3/§4: void and
 * superseded documents "must be... excluded from every sum").
 *
 * Gated by `historical.view` in addition to `reports.view`/`reports.export`
 * (`Perm::withFamilyGate()`) — the reporting-permission pair alone must never
 * grant read access to evidence the Historical module itself would refuse a
 * staff member (see `historical.view`/`historical.import`/`historical.publish`
 * on every Historical route).
 *
 * `paid_amount_snapshot` / `outstanding_amount_snapshot` (and every other
 * snapshot money field except `grand_total`) are nullable — NULL means "source
 * didn't say," frozen at publish (§9.1/§9.3), never a live balance. Native null
 * already renders as a blank cell in every export format (never "0" — see
 * {@see \App\Services\Reporting\Render\ValueFormatter}), a null value is never
 * coerced to 0 when accumulating totals, and a second "Notes on Recorded
 * Amounts" section spells out, per amount column, how many published
 * documents actually have a known figure versus how many are unknown — so an
 * all-unknown column shows "no known amounts", never a total suggesting ₹0.
 */
class HistoricalSalesRegisterDataset extends ReportDatasetService
{
    public const KEY = 'historical-sales-register';
    public const VERSION = 'historical-sales-register@1';

    /** Columns that belong to the main register section, in display order. */
    private const MAIN_KEYS = [
        'document_date', 'original_document_number', 'status', 'customer', 'grand_total',
        'taxable_amount', 'discount_snapshot', 'rounding_snapshot', 'metal_value', 'stone_value',
        'making_amount', 'paid_amount_snapshot', 'outstanding_amount_snapshot', 'flags',
    ];

    /** The one money column the schema guarantees non-null — always accumulated from 0.0. */
    private const TOTALLED = ['grand_total'];

    /**
     * Genuinely nullable snapshot fields (§9.1/§9.3). A total is shown for one
     * of these only once at least one published row in the selection has a
     * known value; see {@see notesSection()} for the per-column disclosure.
     */
    private const NULLABLE_TOTALLED = [
        'taxable_amount', 'discount_snapshot', 'rounding_snapshot',
        'metal_value', 'stone_value', 'making_amount',
        'paid_amount_snapshot', 'outstanding_amount_snapshot',
    ];

    private const AMOUNT_LABELS = [
        'taxable_amount' => 'Taxable Value',
        'discount_snapshot' => 'Discount',
        'rounding_snapshot' => 'Round Off',
        'metal_value' => 'Metal Value',
        'stone_value' => 'Stone Value',
        'making_amount' => 'Making',
        'paid_amount_snapshot' => 'Paid as recorded',
        'outstanding_amount_snapshot' => 'Unpaid as recorded',
    ];

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Historical Sales Register',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('document_date', 'Date', T::Date),
                Col::mandatory('original_document_number', 'Original Invoice Number', T::String),
                Col::mandatory('status', 'Status', T::String),
                Col::mandatory('customer', 'Customer', T::String),
                Col::mandatory('grand_total', 'Grand Total', T::Money),
                Col::optional('taxable_amount', 'Taxable Value', T::Money),
                Col::optional('discount_snapshot', 'Discount', T::Money),
                Col::optional('rounding_snapshot', 'Round Off', T::Money),
                Col::optional('metal_value', 'Metal Value', T::Money),
                Col::optional('stone_value', 'Stone Value', T::Money),
                Col::optional('making_amount', 'Making', T::Money),
                Col::optional('paid_amount_snapshot', 'Paid as recorded', T::Money),
                Col::optional('outstanding_amount_snapshot', 'Unpaid as recorded', T::Money),
                Col::optional('flags', 'Notes', T::String),
                // Notes-section-only columns — one shared catalogue filtered per
                // section, same pattern as Gstr1Dataset's b2b/b2cs/hsn/credit_notes.
                Col::mandatory('metric', 'Particular', T::String),
                Col::mandatory('detail', 'Detail', T::String),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard],
            filters: [
                Filter::for(FK::Period),
                Filter::for(FK::Status),
                Filter::for(FK::Customer),
                Filter::for(FK::Reference),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            // historical.view is the family gate: reports.view/reports.export
            // alone must never surface historical evidence to a staff member
            // the Historical module itself has not granted view access to.
            permissions: Perm::default()->withFamilyGate('historical.view'),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $keys = $request->columnKeys;
        $documents = $this->query($request)->with('customer')->get();

        $mismatchDocIds = in_array('flags', $keys, true)
            ? $this->paidAmountMismatchDocumentIds($documents)
            : [];

        $nullableKeys = array_values(array_intersect(self::NULLABLE_TOTALLED, $keys));

        $rows = [];
        $totals = array_fill_keys(array_intersect(self::TOTALLED, $keys), 0.0);
        $knownSums = array_fill_keys($nullableKeys, 0.0);
        $knownCounts = array_fill_keys($nullableKeys, 0);
        $unknownCounts = array_fill_keys($nullableKeys, 0);
        $publishedCount = 0;

        foreach ($documents as $document) {
            $row = $this->row($document, $keys, $mismatchDocIds);
            $rows[] = $row;

            if ($document->status !== Doc::STATUS_PUBLISHED) {
                continue;
            }
            $publishedCount++;

            foreach ($totals as $key => $_) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }

            foreach ($nullableKeys as $key) {
                $value = $row[$key] ?? null;
                if ($value === null) {
                    $unknownCounts[$key]++;
                } else {
                    $knownSums[$key] += (float) $value;
                    $knownCounts[$key]++;
                }
            }
        }

        $totals = array_map(static fn ($v) => round($v, 2), $totals);
        foreach ($nullableKeys as $key) {
            if ($knownCounts[$key] > 0) {
                $totals[$key] = round($knownSums[$key], 2);
            }
            // Else the key is left OUT of $totals entirely (not set to null):
            // an all-unknown column has no known total to show, and a
            // present-but-null entry would render as "₹0.00" the moment this
            // report gains a second section (PdfRenderer's cross-section
            // grand-total footer treats a null total as 0 — the exact
            // silent-zero bug this report exists to avoid).
        }

        $mainKeys = array_values(array_intersect(self::MAIN_KEYS, $keys));
        $sections = [
            new ReportSection('historical_sales_register', 'Historical Sales Register', $this->cols($def, $mainKeys), $rows, $totals),
        ];

        $notes = $this->notesSection($def, $nullableKeys, $knownCounts, $unknownCounts, $publishedCount);
        if ($notes !== null) {
            $sections[] = $notes;
        }

        return new ReportDataset($sections, $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    /** Read-only query, tenant-scoped via BelongsToShop. Defaults to published-only (§3). */
    private function query(ReportRequest $request)
    {
        $q = Doc::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('document_date', [$period['from'], $period['to']]);
        }

        $status = $request->filter('status');
        $q->where('status', $status ?: Doc::STATUS_PUBLISHED);

        if ($customer = $request->filter('customer')) {
            $q->where('customer_id', $customer);
        }

        if ($reference = $request->filter('reference')) {
            $normalized = HistoricalDocumentIdentity::normalizeNumber((string) $reference);
            if ($normalized !== null) {
                $q->where('original_document_number_normalized', 'like', '%'.addcslashes($normalized, '%_\\').'%');
            }
        }

        return $q->orderBy('document_date')->orderBy('id');
    }

    /**
     * One document → one row of native typed values. `customer_snapshot` is
     * the source of truth for display (§3) — the `customer` relation is
     * advisory only, used when linked.
     *
     * @param  string[]  $keys
     * @param  int[]  $mismatchDocIds
     * @return array<string, mixed>
     */
    private function row(Doc $document, array $keys, array $mismatchDocIds): array
    {
        $all = [
            'document_date' => $document->document_date,
            'original_document_number' => $document->displayNumber(),
            'status' => ucfirst((string) $document->status),
            'customer' => $document->customer?->full_name
                ?: ($document->customer_snapshot['name'] ?? null)
                ?: 'Walk-in',
            'grand_total' => (float) $document->grand_total,
            'taxable_amount' => $this->nullableFloat($document->taxable_amount),
            'discount_snapshot' => $this->nullableFloat($document->discount_snapshot),
            'rounding_snapshot' => $this->nullableFloat($document->rounding_snapshot),
            'metal_value' => $this->nullableFloat($document->metal_value),
            'stone_value' => $this->nullableFloat($document->stone_value),
            'making_amount' => $this->nullableFloat($document->making_amount),
            'paid_amount_snapshot' => $this->nullableFloat($document->paid_amount_snapshot),
            'outstanding_amount_snapshot' => $this->nullableFloat($document->outstanding_amount_snapshot),
            'flags' => $this->flagsFor($document, $mismatchDocIds),
        ];

        return array_intersect_key($all, array_flip($keys));
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value !== null ? (float) $value : null;
    }

    /**
     * Overlap-with-opening-balance and payment-mismatch flags (§3 audit trail).
     * Never affects the query or the totals — purely a visible, per-row note.
     *
     * @param  int[]  $mismatchDocIds
     */
    private function flagsFor(Doc $document, array $mismatchDocIds): ?string
    {
        $flags = [];

        if ($document->opening_balance_overlap) {
            $flags[] = match ($document->opening_balance_resolution) {
                Doc::OPENING_BALANCE_RESOLUTION_INCLUDED => 'Opening balance overlap (included in opening balance)',
                Doc::OPENING_BALANCE_RESOLUTION_SEPARATE => 'Opening balance overlap (kept separate from opening balance)',
                default => 'Opening balance overlap (unresolved)',
            };
        }

        if (in_array($document->id, $mismatchDocIds, true)) {
            $flags[] = 'Paid amount mismatch flagged at import — confirm before relying on the paid figure';
        }

        return $flags === [] ? null : implode('; ', $flags);
    }

    /**
     * Document IDs whose import row carries a persisted `paid_amount_mismatch`
     * warning (`HistoricalImportRow.messages`, set by
     * `HistoricalImportService::applyPaymentSettlement()`). Bulk-fetched once
     * per build, only when the `flags` column is actually selected.
     *
     * @param  \Illuminate\Support\Collection<int, Doc>  $documents
     * @return int[]
     */
    private function paidAmountMismatchDocumentIds($documents): array
    {
        $ids = $documents->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        return HistoricalImportRow::query()
            ->whereIn('historical_sales_document_id', $ids)
            ->get(['historical_sales_document_id', 'messages'])
            ->filter(function ($row) {
                foreach ((array) $row->messages as $message) {
                    if (($message['code'] ?? null) === 'paid_amount_mismatch') {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('historical_sales_document_id')
            ->unique()
            ->all();
    }

    /**
     * "Notes on Recorded Amounts" — one row per selected nullable amount
     * column, spelling out known-vs-unknown counts, plus a fixed disclaimer
     * that this register is a frozen snapshot, not a live receivable (§9.3).
     * Renders in every export format (Screen/PDF/CSV/Excel all print section
     * rows — only totals rows are format-specific), so the "known amounts
     * only" caveat travels with the figures wherever they go.
     *
     * @param  string[]  $nullableKeys
     * @param  array<string,int>  $knownCounts
     * @param  array<string,int>  $unknownCounts
     */
    private function notesSection(ReportDefinition $def, array $nullableKeys, array $knownCounts, array $unknownCounts, int $publishedCount): ?ReportSection
    {
        if ($nullableKeys === [] || $publishedCount === 0) {
            return null;
        }

        $rows = [];
        foreach ($nullableKeys as $key) {
            $label = self::AMOUNT_LABELS[$key] ?? $key;
            $known = $knownCounts[$key];
            $unknown = $unknownCounts[$key];

            $detail = match (true) {
                $known === 0 => "Unknown for every published document in this selection — no known {$label} amounts to total.",
                $unknown === 0 => "Known for all {$known} published document(s) in this selection.",
                default => "Total above reflects {$known} published document(s) with a known amount; "
                    ."{$unknown} published document(s) have no recorded {$label} (shown blank, not ₹0, "
                    .'and excluded from the total).',
            };

            $rows[] = ['metric' => $label, 'detail' => $detail];
        }

        $rows[] = [
            'metric' => 'Collections after import',
            'detail' => 'These figures are frozen as originally recorded at import/publish time. Any '.
                'customer payment received through JewelFlow after cutover lives in the live invoice/'.
                'payment ledger, not in this register.',
        ];

        return new ReportSection('historical_sales_register_notes', 'Notes on Recorded Amounts', $this->cols($def, ['metric', 'detail']), $rows, []);
    }

    /**
     * @param  string[]  $keys
     * @return \App\Services\Reporting\Definition\ColumnDefinition[]
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
}

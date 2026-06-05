<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\LedgerService;
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
 * Cash Flow — opening + in − out = closing over a period, plus the per-entry
 * cash ledger with a running balance (Accounting, frozen §22).
 *
 * Wraps the canonical LedgerService::cashFlow() VERBATIM — the report never
 * re-derives balances. Reconciles BY CONSTRUCTION: closing == opening + cashIn
 * − cashOut, and the running balance ends at closing, all from the service.
 * Operator is a sensitive (permission-gated) column.
 */
class CashFlowDataset extends ReportDatasetService
{
    public const KEY = 'cash-flow';
    public const VERSION = 'cash-flow@1';

    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Cash Flow',
            classification: Cls::Accounting,
            columns: [
                // Summary section
                Col::mandatory('particular', 'Particular', T::String),
                Col::mandatory('value', 'Amount', T::Money),
                // Ledger section
                Col::mandatory('datetime', 'Date / Time', T::DateTime),
                Col::mandatory('type', 'Type', T::String),
                Col::mandatory('source', 'Source', T::String),
                Col::mandatory('amount', 'Amount', T::Money),
                Col::mandatory('running_balance', 'Running Balance', T::Money),
                Col::optional('payment_mode', 'Payment Mode', T::String),
                Col::optional('description', 'Description', T::String),
                Col::sensitive('operator', 'Operator', T::String),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca],
            filters: [
                Filter::for(FK::Period, true),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $keys = $request->columnKeys;
        $data = $this->ledger->cashFlow($request->shopId, $this->period($request));

        // --- Summary (the opening/in/out/closing equation) --------------
        $summaryCols = $this->cols($def, $this->keep(['particular', 'value'], $keys));
        $summaryRows = [
            ['particular' => 'Opening Balance', 'value' => $data->opening],
            ['particular' => 'Cash In', 'value' => $data->cashIn],
            ['particular' => 'Cash Out', 'value' => $data->cashOut],
            ['particular' => 'Closing Balance', 'value' => $data->closing],
        ];
        $summary = new ReportSection('summary', 'Cash Flow Summary', $summaryCols, $summaryRows);

        // --- Cash ledger (running balance) ------------------------------
        $ledgerCols = $this->cols($def, $this->keep(
            ['datetime', 'type', 'source', 'amount', 'running_balance', 'payment_mode', 'description', 'operator'],
            $keys
        ));
        $ledgerRows = [];
        foreach ($data->rows as $r) {
            $ledgerRows[] = [
                'datetime' => $r->occurred_at,
                'type' => $r->type,
                'source' => $r->source,
                'amount' => (float) $r->amount,
                'running_balance' => (float) $r->running_balance,
                'payment_mode' => $r->payment_mode,
                'description' => $r->description,
                'operator' => $r->operator,
            ];
        }
        $ledger = new ReportSection('ledger', 'Cash Ledger', $ledgerCols, $ledgerRows);

        return new ReportDataset([$summary, $ledger], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->ledger->cashFlow($request->shopId, $this->period($request))->count;
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
}

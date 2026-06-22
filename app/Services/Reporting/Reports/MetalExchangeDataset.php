<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\SalesService;
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
 * Old-Metal Exchange — old gold/silver taken in against sales over a period, as
 * transactions plus a gold/silver summary (Operational, retailer edition;
 * GAP 2 tail). Wraps SalesService::metalExchange() VERBATIM. The summary section
 * reconciles BY CONSTRUCTION to the service goldSummary/silverSummary.
 *
 * NOTE: the legacy screen had a secondary "weekly lots" drill-down toggle. That
 * is an interactive vault view, not a flat report; the transaction-level data
 * (the report itself) is migrated here. The weekly-lots drill-down is dropped
 * from this report — the same lots are visible in the vault/metal-ledger views.
 */
class MetalExchangeDataset extends ReportDatasetService
{
    public const KEY = 'metal-exchange';
    public const VERSION = 'metal-exchange@1';

    public function __construct(private readonly SalesService $sales)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Old-Metal Exchange',
            classification: Cls::Operational,
            columns: [
                // transactions
                Col::mandatory('date', 'Date', T::Date),
                Col::optional('invoice', 'Invoice', T::String),
                Col::mandatory('customer', 'Customer', T::String),
                Col::mandatory('metal', 'Metal', T::String),
                Col::optional('purity', 'Purity', T::String),
                Col::mandatory('gross', 'Gross (g)', T::Weight),
                Col::mandatory('fine', 'Fine (g)', T::Weight),
                Col::mandatory('amount', 'Value', T::Money),
                // summary
                Col::mandatory('count', 'Transactions', T::Integer),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [Filter::for(FK::Period, true)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default()->withEdition(ShopEdition::RETAILER),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $keys = $request->columnKeys;
        [$from, $to] = $this->range($request);
        $data = $this->sales->metalExchange($request->shopId, $from, $to);

        // --- transactions -----------------------------------------------
        $txCols = $this->cols($def, $this->keep(['date', 'invoice', 'customer', 'metal', 'purity', 'gross', 'fine', 'amount'], $keys));
        $txRows = [];
        foreach ($data->rows as $p) {
            $isGold = $p->mode === 'old_gold';
            $txRows[] = [
                'date' => $p->created_at ? Carbon::parse($p->created_at)->toDateString() : '',
                'invoice' => (string) ($p->invoice?->invoice_number ?? ''),
                'customer' => (string) ($p->invoice?->customer?->name ?? 'Walk-in'),
                'metal' => $isGold ? 'Gold' : 'Silver',
                'purity' => (string) $p->metal_purity . ($isGold ? 'K' : '‰'),
                'gross' => (float) $p->metal_gross_weight,
                'fine' => (float) $p->metal_fine_weight,
                'amount' => (float) $p->amount,
            ];
        }
        $transactions = new ReportSection('transactions', 'Exchange Transactions', $txCols, $txRows, $this->sum($txRows, $txCols));

        // --- gold / silver summary --------------------------------------
        $sumCols = $this->cols($def, $this->keep(['metal', 'count', 'gross', 'fine', 'amount'], $keys));
        $sumRows = [
            ['metal' => 'Gold', 'count' => (int) $data->goldSummary['count'], 'gross' => (float) $data->goldSummary['gross'], 'fine' => (float) $data->goldSummary['fine'], 'amount' => (float) $data->goldSummary['value']],
            ['metal' => 'Silver', 'count' => (int) $data->silverSummary['count'], 'gross' => (float) $data->silverSummary['gross'], 'fine' => (float) $data->silverSummary['fine'], 'amount' => (float) $data->silverSummary['value']],
        ];
        $summary = new ReportSection('summary', 'Summary', $sumCols, $sumRows, $this->sum($sumRows, $sumCols));

        return new ReportDataset([$summary, $transactions], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        [$from, $to] = $this->range($request);

        return 2 + $this->sales->metalExchange($request->shopId, $from, $to)->rows->count();
    }

    /** @return array{0:string,1:string} from/to Y-m-d for the service. */
    private function range(ReportRequest $request): array
    {
        $period = $request->filter('period', []);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        return [
            $from ? $from->toDateString() : Carbon::now()->startOfMonth()->toDateString(),
            $to ? $to->toDateString() : Carbon::now()->toDateString(),
        ];
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
            $totals[$col->key] = round($sum, $col->type === T::Weight ? 3 : 2);
        }

        return $totals;
    }
}

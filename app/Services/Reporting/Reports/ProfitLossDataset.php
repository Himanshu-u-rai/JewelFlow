<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\ProfitReportingService;
use App\Reporting\ReportPeriod;
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

/**
 * Profit & Loss — honest gross margin for a period (OWNER class, frozen §22 / P4).
 *
 * Wraps the canonical ProfitReportingService::summary() VERBATIM — the report
 * layer reimplements NO revenue, COGS or margin maths. Revenue is the Sales
 * Register scope (Σ finalized invoices.subtotal − discount − credit-note
 * returns), COGS is Σ items.cost_price of sold lines (the Inventory Valuation
 * cost basis), and margin = revenue − COGS, all from the service. Cost is a
 * confidential dimension, so the document is watermarked CONFIDENTIAL.
 *
 * Lines sold with no recorded cost are excluded from COGS and surfaced as a
 * visible "Lines Missing Cost" data-quality metric so the margin is never
 * silently inflated.
 */
class ProfitLossDataset extends ReportDatasetService
{
    public const KEY = 'pnl';
    public const VERSION = 'pnl@1';

    public function __construct(private readonly ProfitReportingService $profit)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Profit & Loss',
            classification: Cls::Owner,
            columns: [
                Col::mandatory('metric', 'Particular', T::String),
                Col::mandatory('amount', 'Amount', T::Money),
                Col::mandatory('percent', 'Margin %', T::Percent),
                Col::mandatory('count', 'Count', T::Integer),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca],
            filters: [
                Filter::for(FK::Period, true),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
            watermarkBaseline: 'CONFIDENTIAL',
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $data = $this->profit->summary($request->shopId, $this->period($request));

        // --- Profit & Loss statement (money lines, all from the service) ---
        $pnlCols = $this->cols($def, ['metric', 'amount']);
        $pnl = new ReportSection('pnl', 'Profit & Loss', $pnlCols, [
            ['metric' => 'Gross Sales', 'amount' => $data->grossSales],
            ['metric' => 'Discount', 'amount' => $data->discount],
            ['metric' => 'Returns', 'amount' => $data->returns],
            ['metric' => 'Net Revenue', 'amount' => $data->revenue],
            ['metric' => 'Cost of Goods Sold', 'amount' => $data->cogs],
            ['metric' => 'Gross Profit', 'amount' => $data->grossProfit],
            ['metric' => 'Making Charges', 'amount' => $data->makingCharges],
            ['metric' => 'Stone Charges', 'amount' => $data->stoneCharges],
            ['metric' => 'Wastage Recovered', 'amount' => $data->wastageRecovered],
        ]);

        // --- Margin (Percent) ------------------------------------------
        $marginCols = $this->cols($def, ['metric', 'percent']);
        $margin = new ReportSection('margin', 'Margin', $marginCols, [
            ['metric' => 'Gross Margin', 'percent' => $data->marginPct],
        ]);

        // --- Data Quality (counts; surfaces cost-unknown lines) --------
        $dqCols = $this->cols($def, ['metric', 'count']);
        $dataQuality = new ReportSection('data_quality', 'Data Quality', $dqCols, [
            ['metric' => 'Sold Lines', 'count' => $data->soldLineCount],
            ['metric' => 'Lines Missing Cost', 'count' => $data->costUnknownLines],
        ]);

        return new ReportDataset([$pnl, $margin, $dataQuality], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return 12; // 9 statement + 1 margin + 2 data-quality
    }

    private function period(ReportRequest $request): ReportPeriod
    {
        $period = $request->filter('period', []);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        return ReportPeriod::range($from ? $from->toDateString() : null, $to ? $to->toDateString() : null);
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

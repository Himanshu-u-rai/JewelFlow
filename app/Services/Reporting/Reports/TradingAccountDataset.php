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
use App\Services\StockAsAt;
use App\Services\YearEndStock;
use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Trading account with stock for a financial year:
 *
 *   cost of goods sold = opening stock + purchases - closing stock
 *   gross profit       = net sales - cost of goods sold
 *
 * Opening and closing stock use the year-end valuation (lower of cost and market; the latest snapshot when one
 * exists, else computed live — see YearEndStock). Purchases are the intake movements dated inside the year at their
 * acquisition cost; a piece that came back from a customer is not a purchase (its sale is already netted out of
 * sales). Net sales are exactly what Profit & Loss counts (ProfitReportingService): finalized sales less discount
 * less credit-note returns, ex-GST.
 *
 * A piece with no recorded cost or no closing rate is left out of the value it would have joined and shown in the
 * Data Quality counts — never guessed — so a figure here can only understate what is incomplete, visibly.
 */
class TradingAccountDataset extends ReportDatasetService
{
    use StockYearReport;

    public const KEY = 'trading-account';
    public const VERSION = 'trading-account@1';

    public const NET_SALES = 'Net sales (after discount and returns)';
    public const OPENING = 'Opening stock';
    public const PURCHASES = 'Add: Purchases';
    public const CLOSING = 'Less: Closing stock';
    public const COGS = 'Cost of goods sold';
    public const GROSS_PROFIT = 'Gross profit';
    public const DQ_OPENING_NOT_VALUED = 'Opening stock pieces not valued (no cost or no rate)';
    public const DQ_PURCHASES_NO_COST = 'Purchased pieces with no recorded cost (left out of purchases)';
    public const DQ_CLOSING_NOT_VALUED = 'Closing stock pieces not valued (no cost or no rate)';

    public function __construct(private readonly ProfitReportingService $profit)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Trading Account with Stock',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('particular', 'Particular', T::String),
                Col::mandatory('amount', 'Amount', T::Money),
                Col::mandatory('percent', 'Gross Margin %', T::Percent),
                Col::mandatory('count', 'Count', T::Integer),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca],
            filters: [Filter::for(FK::Period, true)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default()->withView('reports.money'),
            watermarkBaseline: 'CONFIDENTIAL',
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $fy = $this->yearOf($request);

        $opening = YearEndStock::forYear($request->shopId, $fy->previous());
        $closing = YearEndStock::forYear($request->shopId, $fy);
        [$openingValue, $openingMissing] = $this->valued($opening['rows']);
        [$closingValue, $closingMissing] = $this->valued($closing['rows']);
        [$purchases, $purchasesMissing] = $this->purchases($request->shopId, $fy, $closing['as_at']);

        $sales = $this->profit->summary($request->shopId, ReportPeriod::range($fy->startDate(), $fy->endDate()));
        $cogs = round($openingValue + $purchases - $closingValue, 2);
        $gross = round($sales->revenue - $cogs, 2);

        $trading = new ReportSection('trading', 'Trading Account', $this->cols($def, ['particular', 'amount']), [
            ['particular' => self::NET_SALES, 'amount' => $sales->revenue],
            ['particular' => self::OPENING, 'amount' => $openingValue],
            ['particular' => self::PURCHASES, 'amount' => $purchases],
            ['particular' => self::CLOSING, 'amount' => $closingValue],
            ['particular' => self::COGS, 'amount' => $cogs],
            ['particular' => self::GROSS_PROFIT, 'amount' => $gross],
        ]);
        $margin = new ReportSection('margin', 'Margin', $this->cols($def, ['particular', 'percent']), [
            ['particular' => 'Gross Margin', 'percent' => $sales->revenue > 0 ? round($gross / $sales->revenue * 100, 2) : null],
        ]);
        $quality = new ReportSection('data_quality', 'Data Quality', $this->cols($def, ['particular', 'count']), [
            ['particular' => self::DQ_OPENING_NOT_VALUED, 'count' => $openingMissing],
            ['particular' => self::DQ_PURCHASES_NO_COST, 'count' => $purchasesMissing],
            ['particular' => self::DQ_CLOSING_NOT_VALUED, 'count' => $closingMissing],
        ]);

        $meta = $this->withNotes($meta, [
            'Financial year' => $fy->label(),
            'Opening stock source' => $opening['source'],
            'Closing stock source' => $closing['source'],
        ] + $this->historyNote($request->shopId, [$opening['as_at'], $closing['as_at']]));

        return new ReportDataset([$trading, $margin, $quality], $meta);
    }

    /** @return array{0: float, 1: int} total valuation of the valued pieces, and how many were left out */
    private function valued(array $rows): array
    {
        $total = 0.0;
        $missing = 0;
        foreach ($rows as $r) {
            if ($r['valuation'] === null) {
                $missing++;
            } else {
                $total += $r['valuation'];
            }
        }

        return [round($total, 2), $missing];
    }

    /** @return array{0: float, 1: int} cost of pieces taken into stock inside the year, and pieces with no cost */
    private function purchases(int $shopId, FinancialYear $fy, string $asAt): array
    {
        $statuses = StockAsAt::STOCK_STATUSES;
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $start = CarbonImmutable::parse($fy->startDate(), FinancialYear::TIMEZONE)->format('Y-m-d H:i:s');
        $end = CarbonImmutable::parse($asAt, FinancialYear::TIMEZONE)->addDay()->startOfDay()->format('Y-m-d H:i:s');

        $r = DB::selectOne(<<<SQL
            WITH m AS (
                SELECT id, item_id, movement_type, to_status, acquisition_cost, occurred_at,
                       LAG(to_status) OVER (PARTITION BY item_id ORDER BY occurred_at, id) AS prev_status
                FROM item_movements
                WHERE shop_id = ? AND occurred_at < ?
            )
            SELECT COALESCE(SUM(COALESCE(m.acquisition_cost, i.acquisition_cost)), 0) AS cost,
                   COUNT(*) FILTER (WHERE COALESCE(m.acquisition_cost, i.acquisition_cost) IS NULL) AS unknown
            FROM m LEFT JOIN items i ON i.id = m.item_id
            WHERE m.occurred_at >= ? AND m.movement_type = 'intake' AND m.prev_status IS NULL AND m.to_status IN ({$in})
        SQL, array_merge([$shopId, $end, $start], $statuses));

        return [round((float) $r->cost, 2), (int) $r->unknown];
    }
}

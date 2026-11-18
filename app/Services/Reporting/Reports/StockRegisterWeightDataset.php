<?php

namespace App\Services\Reporting\Reports;

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
use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stock register by weight — the quantitative details Form 3CD clause 35 asks for, per metal and purity:
 *
 *   opening + received - sold - melted - other outflows = closing (computed)      vs      closing (actual)
 *
 * Opening and closing (actual) are the stock rebuilt from the item_movements ledger at the end of the previous year
 * and of this year (StockAsAt); the flows are the ledger's own movements dated inside the year (Indian time). A
 * piece counts as received when its status moves from not-owned to owned stock (including stock sitting with a
 * karigar) and as an outflow when it moves the other way, judged against the piece's previous movement by time so a
 * back-dated entry is placed correctly. The "difference" line must be zero when the ledger is consistent; anything
 * else is a shortage (-) or excess (+) in grams or pieces. No money is shown, so reports.view is enough.
 */
class StockRegisterWeightDataset extends ReportDatasetService
{
    use StockYearReport;

    public const KEY = 'stock-register-weight';
    public const VERSION = 'stock-register-weight@1';

    public const OPENING = 'Opening stock';
    public const PURCHASES = 'Add: Purchases';
    public const MANUFACTURED = 'Add: Manufactured';
    public const RETURNED = 'Add: Returned to stock';
    public const OTHER_RECEIPTS = 'Add: Opening entries and other receipts';
    public const SOLD = 'Less: Sold';
    public const MELTED = 'Less: Melted';
    public const OTHER_OUT = 'Less: Transferred, reversed, returned or written off';
    public const CLOSING_COMPUTED = 'Closing stock (computed)';
    public const CLOSING_ACTUAL = 'Closing stock (actual)';
    public const DIFFERENCE = 'Difference (shortage - / excess +)';

    private const ADDS = [self::PURCHASES, self::MANUFACTURED, self::RETURNED, self::OTHER_RECEIPTS];
    private const LESS = [self::SOLD, self::MELTED, self::OTHER_OUT];

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Stock Register by Weight',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('metal', 'Metal', T::String),
                Col::mandatory('purity', 'Purity', T::Decimal),
                Col::mandatory('particular', 'Particular', T::String),
                Col::mandatory('count', 'Pieces', T::Integer),
                Col::mandatory('gross_weight', 'Gross Wt (g)', T::Weight),
                Col::mandatory('net_weight', 'Net Metal Wt (g)', T::Weight),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [Filter::for(FK::Period, true)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $fy = $this->yearOf($request);
        $today = CarbonImmutable::now(FinancialYear::TIMEZONE)->toDateString();
        $asAt = min($fy->endDate(), $today);

        $g = [];
        $add = function (array $s, string $line) use (&$g) {
            $metal = (string) ($s['metal'] ?? 'unknown');
            $key = $metal . '|' . sprintf('%08.3f', (float) $s['purity']);
            $g[$key]['metal'] = $metal;
            $g[$key]['purity'] = (float) $s['purity'];
            foreach (['count', 'gross', 'net'] as $i => $f) {
                $g[$key][$line][$f] = ($g[$key][$line][$f] ?? 0) + $s[$f];
            }
        };

        foreach ($this->held($request->shopId, $fy->previous()->endDate()) as $s) {
            $add($s, self::OPENING);
        }
        foreach ($this->held($request->shopId, $asAt) as $s) {
            $add($s, self::CLOSING_ACTUAL);
        }
        foreach ($this->flows($request->shopId, $fy) as $s) {
            $add($s, $s['line']);
        }
        ksort($g);

        $cols = $this->cols($def, ['metal', 'purity', 'particular', 'count', 'gross_weight', 'net_weight']);
        $rows = [];
        foreach ($g as $grp) {
            $get = fn (string $line, string $f) => (float) ($grp[$line][$f] ?? 0);
            $computed = [];
            foreach (['count', 'gross', 'net'] as $f) {
                $computed[$f] = $get(self::OPENING, $f)
                    + array_sum(array_map(fn ($l) => $get($l, $f), self::ADDS))
                    - array_sum(array_map(fn ($l) => $get($l, $f), self::LESS));
            }
            $lines = [];
            foreach ([self::OPENING, ...self::ADDS, ...self::LESS] as $l) {
                $lines[$l] = [$get($l, 'count'), $get($l, 'gross'), $get($l, 'net')];
            }
            $lines[self::CLOSING_COMPUTED] = [$computed['count'], $computed['gross'], $computed['net']];
            $lines[self::CLOSING_ACTUAL] = [$get(self::CLOSING_ACTUAL, 'count'), $get(self::CLOSING_ACTUAL, 'gross'), $get(self::CLOSING_ACTUAL, 'net')];
            $lines[self::DIFFERENCE] = [
                $lines[self::CLOSING_ACTUAL][0] - $computed['count'],
                $lines[self::CLOSING_ACTUAL][1] - $computed['gross'],
                $lines[self::CLOSING_ACTUAL][2] - $computed['net'],
            ];
            foreach ($lines as $label => [$count, $gross, $net]) {
                $rows[] = $this->shape([
                    'metal' => $grp['metal'], 'purity' => $grp['purity'], 'particular' => $label,
                    'count' => (int) round($count), 'gross_weight' => $this->w($gross), 'net_weight' => $this->w($net),
                ], $cols);
            }
        }

        $meta = $this->withNotes($meta, ['Financial year' => $fy->label(), 'Stock as at' => $asAt]
            + $this->historyNote($request->shopId, [$fy->previous()->endDate(), $asAt]));

        return new ReportDataset([new ReportSection('register', 'Stock Register by Weight (Form 3CD clause 35)', $cols, $rows)], $meta);
    }

    /** Weights are stored to 6 places; float noise below that is not a difference. */
    private function w(float $v): float
    {
        $v = round($v, 6);

        return $v == 0.0 ? 0.0 : round($v, 4);
    }

    /** @return array<int, array{metal: ?string, purity: float, count: int, gross: float, net: float}> */
    private function held(int $shopId, string $date): array
    {
        $out = [];
        foreach (StockAsAt::itemsInStock($shopId, $date) as $r) {
            $key = ($r->metal_type ?? 'unknown') . '|' . (float) $r->purity;
            $o = &$out[$key];
            $o ??= ['metal' => $r->metal_type, 'purity' => (float) $r->purity, 'count' => 0, 'gross' => 0.0, 'net' => 0.0];
            $o['count']++;
            $o['gross'] += (float) $r->gross_weight;
            $o['net'] += (float) $r->net_metal_weight;
            unset($o);
        }

        return array_values($out);
    }

    /**
     * Movements dated inside the year that change whether the shop owns the piece, classified into register lines.
     * The previous state of each piece is its previous movement by (occurred_at, id) — the same order StockAsAt uses.
     */
    private function flows(int $shopId, FinancialYear $fy): array
    {
        $statuses = StockAsAt::STOCK_STATUSES;
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $start = CarbonImmutable::parse($fy->startDate(), FinancialYear::TIMEZONE)->format('Y-m-d H:i:s');
        $end = CarbonImmutable::parse($fy->endDate(), FinancialYear::TIMEZONE)->addDay()->startOfDay()->format('Y-m-d H:i:s');

        $rows = DB::select(<<<SQL
            WITH m AS (
                SELECT movement_type, reference_type, metal_type, purity, to_status, gross_weight, net_metal_weight, occurred_at,
                       LAG(to_status) OVER (PARTITION BY item_id ORDER BY occurred_at, id) AS prev_status
                FROM item_movements
                WHERE shop_id = ? AND occurred_at < ?
            )
            SELECT metal_type, purity, movement_type, reference_type,
                   CASE WHEN to_status IN ({$in}) THEN 1 ELSE 0 END AS to_in,
                   COUNT(*) AS n, COALESCE(SUM(gross_weight), 0) AS gross, COALESCE(SUM(net_metal_weight), 0) AS net
            FROM m
            WHERE occurred_at >= ?
              AND (CASE WHEN to_status IN ({$in}) THEN 1 ELSE 0 END) <> (CASE WHEN prev_status IN ({$in}) THEN 1 ELSE 0 END)
            GROUP BY metal_type, purity, movement_type, reference_type, (CASE WHEN to_status IN ({$in}) THEN 1 ELSE 0 END)
        SQL, array_merge([$shopId, $end], $statuses, [$start], $statuses, $statuses, $statuses));

        $out = [];
        foreach ($rows as $r) {
            $line = (int) $r->to_in === 1
                ? match (true) {
                    $r->movement_type === 'intake' && $r->reference_type === 'stock_purchase' => self::PURCHASES,
                    $r->movement_type === 'intake' && $r->reference_type === 'job_order' => self::MANUFACTURED,
                    $r->movement_type === 'returned_to_stock' => self::RETURNED,
                    default => self::OTHER_RECEIPTS,
                }
                : match ($r->movement_type) {
                    'sold' => self::SOLD,
                    'melted' => self::MELTED,
                    default => self::OTHER_OUT,
                };
            $out[] = ['metal' => $r->metal_type, 'purity' => (float) $r->purity, 'count' => (int) $r->n,
                'gross' => (float) $r->gross, 'net' => (float) $r->net, 'line' => $line];
        }

        return $out;
    }
}

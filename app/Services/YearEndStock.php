<?php

namespace App\Services;

use App\Models\MetalRate;
use App\Support\FinancialYear;
use App\Support\StockValuation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The stock a shop held at the end of a financial year, valued at the lower of acquisition cost and the closing
 * rate (AS-2 / ICDS-IV). One implementation for the year-end snapshot (which freezes it) and the year reports
 * (which read the latest snapshot when there is one, else compute it live), so the two can never disagree.
 */
final class YearEndStock
{
    /**
     * Live rows for the stock held at 23:59:59 IST on $date. Values are raw database values (strings/numerics),
     * ready to insert into fiscal_year_snapshot_items once shop_id and snapshot_id are added.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function liveRows(int $shopId, string $date): array
    {
        $stock = StockAsAt::itemsInStock($shopId, $date);
        $meta = self::itemDetails($stock->pluck('item_id')->all());
        $rates = [];
        $rateDates = [];
        $rows = [];
        foreach ($stock as $s) {
            $key = $s->metal_type . '|' . (float) $s->purity;
            if (! array_key_exists($key, $rates)) {
                // The rate in force at year-end: 31 March's, else the last day before it with a rate (shops
                // skip holidays). A rate after year-end is never used.
                $rate = $s->metal_type !== null && $s->purity !== null
                    ? MetalRate::latestResolvedOnOrBefore($shopId, $date, (string) $s->metal_type, (float) $s->purity)
                    : null;
                $rates[$key] = $rate ? (float) $rate->rate_per_gram : null;
                $rateDates[$key] = $rate ? substr((string) $rate->business_date, 0, 10) : null;
            }
            $market = StockValuation::marketValue($s->net_metal_weight, $rates[$key]);
            $cost = $s->acquisition_cost;
            $m = $meta[(int) $s->item_id] ?? null;
            $rows[] = [
                'item_id' => (int) $s->item_id,
                'barcode' => $m->barcode ?? null,
                'design' => $m->design ?? null,
                'category' => $m->category ?? null,
                'metal_type' => $s->metal_type,
                'purity' => $s->purity,
                'gross_weight' => $s->gross_weight,
                'net_metal_weight' => $s->net_metal_weight,
                'acquisition_cost' => $cost,
                'acquisition_cost_source' => $cost === null ? 'unknown' : ($m->acquisition_cost_source ?? 'entered'),
                'closing_rate_per_gram' => $rates[$key],
                'closing_rate_date' => $rateDates[$key],
                'value_at_closing_rate' => $market,
                'valuation' => StockValuation::lowerOfCostAndMarket($cost, $market),
            ];
        }

        return $rows;
    }

    /**
     * What the year reports show for a year: the latest snapshot when the year has ended and one exists, otherwise a
     * live computation. A year still running is valued as at today (it has no 31 March yet).
     *
     * @return array{rows: array<int, array<string, mixed>>, as_at: string, source: string, snapshot: ?object}
     */
    public static function forYear(int $shopId, FinancialYear $fy): array
    {
        $today = CarbonImmutable::now(FinancialYear::TIMEZONE)->toDateString();
        $asAt = min($fy->endDate(), $today);

        $snapshot = $asAt === $fy->endDate() && $today > $fy->endDate()
            ? app(FiscalYearSnapshotService::class)->latest($shopId, $fy)
            : null;

        if ($snapshot) {
            $rows = DB::table('fiscal_year_snapshot_items')->where('snapshot_id', $snapshot->id)->orderBy('item_id')->get()
                ->map(fn ($r) => self::typed((array) $r))->all();

            return [
                'rows' => $rows,
                'as_at' => $asAt,
                'source' => 'From snapshot v' . $snapshot->version . ' taken ' . substr((string) $snapshot->taken_at, 0, 16),
                'snapshot' => $snapshot,
                'basis' => app(FiscalYearSnapshotService::class)->basisOf($snapshot),
            ];
        }

        return [
            'rows' => array_map(fn ($r) => self::typed($r), self::liveRows($shopId, $asAt)),
            'as_at' => $asAt,
            'source' => 'Live — no snapshot yet' . ($asAt < $fy->endDate() ? ' (year not over: as at ' . $asAt . ')' : ''),
            'snapshot' => null,
            'basis' => StockHistory::basisForDate($shopId, $asAt),
        ];
    }

    /** Native numbers (null stays null: an unknown is never turned into zero). */
    private static function typed(array $r): array
    {
        $f = fn ($v) => $v === null ? null : (float) $v;

        return [
            'item_id' => (int) $r['item_id'],
            'barcode' => $r['barcode'],
            'design' => $r['design'],
            'category' => $r['category'],
            'metal_type' => $r['metal_type'],
            'purity' => $f($r['purity']),
            'gross_weight' => $f($r['gross_weight']),
            'net_metal_weight' => $f($r['net_metal_weight']),
            'acquisition_cost' => $f($r['acquisition_cost']),
            'acquisition_cost_source' => $r['acquisition_cost_source'],
            'closing_rate_per_gram' => $f($r['closing_rate_per_gram']),
            'closing_rate_date' => $r['closing_rate_date'] === null ? null : substr((string) $r['closing_rate_date'], 0, 10),
            'value_at_closing_rate' => $f($r['value_at_closing_rate']),
            'valuation' => $f($r['valuation']),
        ];
    }

    /** @return array<int, object> */
    public static function itemDetails(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_map('intval', $ids), 1000) as $chunk) {
            foreach (DB::table('items')->whereIn('id', $chunk)->get(['id', 'barcode', 'design', 'category', 'acquisition_cost_source']) as $i) {
                $out[(int) $i->id] = $i;
            }
        }

        return $out;
    }
}

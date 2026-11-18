<?php

namespace App\Services;

use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Year-end snapshot (owner-approved 2026-11-16): a frozen, append-only copy of the stock a shop held at the end of a
 * financial year, valued at the lower of acquisition cost and the 31 March closing rate (AS-2 / ICDS-IV), plus the
 * balances as at that date. It never touches shop_rules.financial_lock_date (Constitution Art. VII.9: locking is
 * always explicit). A snapshot is evidence, not a lock.
 */
class FiscalYearSnapshotService
{
    public const KIND_AUTOMATIC = 'automatic';
    public const KIND_CLOSING = 'closing';

    /** Takes a snapshot. 'automatic' happens once per (shop, year); 'closing' makes a new version every time. */
    public function take(int $shopId, FinancialYear $fy, string $kind, ?int $userId = null): object
    {
        if (! in_array($kind, [self::KIND_AUTOMATIC, self::KIND_CLOSING], true)) {
            throw new InvalidArgumentException("Unknown snapshot kind: {$kind}");
        }
        $today = CarbonImmutable::now(FinancialYear::TIMEZONE)->toDateString();
        if ($today <= $fy->endDate()) {
            throw new InvalidArgumentException("Financial year {$fy->label()} has not ended yet.");
        }

        return DB::transaction(function () use ($shopId, $fy, $kind, $userId) {
            // One writer per (shop, year): held until commit, so versions can never collide.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), ?)', ['fiscal_year_snapshot:' . $fy->label(), $shopId]);

            if ($kind === self::KIND_AUTOMATIC) {
                $existing = DB::table('fiscal_year_snapshots')
                    ->where(['shop_id' => $shopId, 'fiscal_year' => $fy->label(), 'kind' => self::KIND_AUTOMATIC])->first();
                if ($existing) {
                    return $existing;
                }
            }

            $version = 1 + (int) DB::table('fiscal_year_snapshots')
                ->where(['shop_id' => $shopId, 'fiscal_year' => $fy->label()])->max('version');

            $date = $fy->endDate();
            // The same rows the year reports compute live (YearEndStock), frozen here.
            $rows = array_map(fn ($r) => ['shop_id' => $shopId] + $r, YearEndStock::liveRows($shopId, $date));

            $id = DB::table('fiscal_year_snapshots')->insertGetId([
                'shop_id' => $shopId,
                'fiscal_year' => $fy->label(),
                'version' => $version,
                'kind' => $kind,
                'as_at_date' => $date,
                'taken_at' => DB::raw('clock_timestamp()'),
                'taken_by' => $userId,
                // Stored explicitly: reconstructed when the year ended before exact stock history began (StockHistory).
                'basis' => StockHistory::basisForDate($shopId, $date),
                'totals' => json_encode($this->totals($rows, $shopId, $date)),
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('fiscal_year_snapshot_items')->insert(array_map(fn ($r) => $r + ['snapshot_id' => $id], $chunk));
            }

            return DB::table('fiscal_year_snapshots')->where('id', $id)->first();
        });
    }

    /**
     * 'exact' or 'reconstructed'. Snapshots taken before the basis column existed have NULL (the table is append-only,
     * so they cannot be rewritten): those are judged from the shop's exact-from instant.
     */
    public function basisOf(object $snapshot): string
    {
        return $snapshot->basis ?? StockHistory::basisForDate((int) $snapshot->shop_id, substr((string) $snapshot->as_at_date, 0, 10));
    }

    public function latest(int $shopId, FinancialYear $fy): ?object
    {
        return DB::table('fiscal_year_snapshots')
            ->where(['shop_id' => $shopId, 'fiscal_year' => $fy->label()])
            ->orderByDesc('version')->first();
    }

    /**
     * What differs now from the snapshot, for that year-end: items that joined or left the year-end stock, movements
     * recorded after the snapshot but dated inside the year, and 'books.backdated' audit events since.
     */
    public function changesSince(object $snapshot): array
    {
        $shopId = (int) $snapshot->shop_id;
        $date = substr((string) $snapshot->as_at_date, 0, 10);
        $fy = FinancialYear::forDate($date);

        $then = DB::table('fiscal_year_snapshot_items')->where('snapshot_id', $snapshot->id)->get()->keyBy('item_id');
        $now = StockAsAt::itemsInStock($shopId, $date)->keyBy(fn ($r) => (int) $r->item_id);

        $addedIds = $now->keys()->diff($then->keys())->values()->all();
        $details = $this->itemDetails($addedIds);
        $added = array_map(fn ($id) => [
            'item_id' => $id,
            'barcode' => $details[$id]->barcode ?? null,
            'design' => $details[$id]->design ?? null,
            'metal_type' => $now[$id]->metal_type,
            'purity' => $now[$id]->purity,
            'net_metal_weight' => $now[$id]->net_metal_weight,
        ], $addedIds);
        $removed = $then->keys()->diff($now->keys())->map(fn ($id) => [
            'item_id' => (int) $id,
            'barcode' => $then[$id]->barcode,
            'design' => $then[$id]->design,
            'metal_type' => $then[$id]->metal_type,
            'purity' => $then[$id]->purity,
            'net_metal_weight' => $then[$id]->net_metal_weight,
        ])->values()->all();

        $endExclusive = CarbonImmutable::parse($date, FinancialYear::TIMEZONE)->addDay()->format('Y-m-d H:i:s');
        $late = DB::table('item_movements')
            ->where('shop_id', $shopId)
            ->whereRaw('is_backfilled = false') // rebuilt history is not a "change"
            ->where('occurred_at', '<', $endExclusive)
            ->where('created_at', '>', $snapshot->taken_at)
            ->orderBy('id')->get()
            ->map(fn ($m) => (array) $m)->all();

        $events = DB::table('audit_logs')
            ->where('shop_id', $shopId)
            ->where('action', 'books.backdated')
            ->whereRaw("created_at > (? ::timestamp AT TIME ZONE current_setting('TimeZone')) AT TIME ZONE 'Asia/Kolkata'", [$snapshot->taken_at])
            ->orderBy('id')->get()
            ->filter(fn ($e) => $this->eventTouchesYear($e, $fy))
            ->map(fn ($e) => (array) $e)->values()->all();

        return [
            'snapshot_id' => (int) $snapshot->id,
            'as_at_date' => $date,
            'added' => array_values($added),
            'removed' => $removed,
            'late_movements' => $late,
            'backdated_events' => $events,
            'has_changes' => $added !== [] || $removed !== [] || $late !== [] || $events !== [],
        ];
    }

    /** An event with a recognisable document date outside the year is another year's business; with none, show it. */
    private function eventTouchesYear(object $event, FinancialYear $fy): bool
    {
        $data = is_string($event->data ?? null) ? json_decode($event->data, true) : (array) ($event->data ?? []);
        foreach (['document_date', 'date', 'business_date', 'as_of'] as $k) {
            if (is_array($data) && isset($data[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $data[$k])) {
                return $fy->contains(substr((string) $data[$k], 0, 10));
            }
        }

        return true;
    }

    private function itemDetails(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_map('intval', $ids), 1000) as $chunk) {
            foreach (DB::table('items')->whereIn('id', $chunk)->get(['id', 'barcode', 'design', 'category', 'acquisition_cost_source']) as $i) {
                $out[(int) $i->id] = $i;
            }
        }

        return $out;
    }

    private function totals(array $rows, int $shopId, string $date): array
    {
        $groups = [];
        $total = ['item_count' => 0, 'unknown_cost_count' => 0, 'total_acquisition_cost' => 0.0, 'total_valuation' => 0.0];
        foreach ($rows as $r) {
            $key = $r['metal_type'] . '|' . (float) $r['purity'];
            $g = &$groups[$key];
            $g ??= ['metal_type' => $r['metal_type'], 'purity' => $r['purity'], 'item_count' => 0, 'gross_weight' => 0.0,
                'net_metal_weight' => 0.0, 'total_acquisition_cost' => 0.0, 'valuation' => 0.0, 'unknown_cost_count' => 0];
            $g['item_count']++;
            $g['gross_weight'] += (float) $r['gross_weight'];
            $g['net_metal_weight'] += (float) $r['net_metal_weight'];
            $g['total_acquisition_cost'] += (float) $r['acquisition_cost'];
            $g['valuation'] += (float) $r['valuation'];
            $total['item_count']++;
            $total['total_acquisition_cost'] += (float) $r['acquisition_cost'];
            $total['total_valuation'] += (float) $r['valuation'];
            if ($r['acquisition_cost'] === null) {
                $g['unknown_cost_count']++;
                $total['unknown_cost_count']++;
            }
            unset($g);
        }
        $round = fn (array $a) => array_map(fn ($v) => is_float($v) ? round($v, 6) : $v, $a);

        return $round($total) + [
            'by_metal' => array_map($round, array_values($groups)),
            'balances' => class_exists(\App\Services\BalancesAsAt::class) ? app(\App\Services\BalancesAsAt::class)->forShop($shopId, $date) : null,
        ];
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 — Parity verification for the dual-write rate migration.
 *
 * Compares every (shop_id, business_date) pair between:
 *   - LEGACY:  shop_daily_metal_rates (per-metal columns)
 *   - NEW:     shop_daily_metal_rate_entries (row per metal_type)
 *
 * Reports any mismatch. STOP condition for Stage B advancement: this
 * command must report zero mismatches across multiple consecutive
 * business days before readers may switch to the new table.
 *
 * Read-only — never auto-corrects. If a divergence appears, investigate
 * via the service layer per CONSTITUTION.md §3 Lane 2.
 *
 * Exit codes:
 *   0 = parity proven (no mismatches anywhere)
 *   1 = mismatch detected (see output for details)
 */
class ReconcileRateShadowWrite extends Command
{
    protected $signature = 'rates:reconcile-shadow-write
                            {--shop= : Limit to one shop ID (omit for all shops)}
                            {--date= : Limit to one business_date YYYY-MM-DD (omit for all dates)}
                            {--tolerance=0.0001 : Acceptable rate delta per metal (default 0.0001/g)}';

    protected $description = 'Verify parity between legacy shop_daily_metal_rates columns and new shop_daily_metal_rate_entries rows. Read-only — exits 1 on mismatch.';

    public function handle(): int
    {
        $tolerance = (float) $this->option('tolerance');
        $shopFilter = $this->option('shop') !== null ? (int) $this->option('shop') : null;
        $dateFilter = $this->option('date');

        $legacyQuery = DB::table('shop_daily_metal_rates');
        if ($shopFilter !== null) {
            $legacyQuery->where('shop_id', $shopFilter);
        }
        if ($dateFilter !== null) {
            $legacyQuery->whereDate('business_date', $dateFilter);
        }

        $legacyRows = $legacyQuery
            ->select(['shop_id', 'business_date', 'gold_24k_rate_per_gram', 'silver_999_rate_per_gram'])
            ->orderBy('shop_id')
            ->orderBy('business_date')
            ->get();

        if ($legacyRows->isEmpty()) {
            $this->info('No legacy rows to compare. Parity vacuously true.');
            return 0;
        }

        $mismatches = [];
        $missingNew = [];
        $totalChecked = 0;

        foreach ($legacyRows as $legacy) {
            $newRows = DB::table('shop_daily_metal_rate_entries')
                ->where('shop_id', $legacy->shop_id)
                ->whereDate('business_date', $legacy->business_date)
                ->whereIn('metal_type', ['gold', 'silver'])
                ->pluck('rate_per_gram', 'metal_type');

            foreach ([
                ['gold',   (float) $legacy->gold_24k_rate_per_gram],
                ['silver', (float) $legacy->silver_999_rate_per_gram],
            ] as [$metal, $legacyRate]) {
                if ($legacyRate <= 0) {
                    continue;
                }
                $totalChecked++;

                $newRate = isset($newRows[$metal]) ? (float) $newRows[$metal] : null;

                if ($newRate === null) {
                    $missingNew[] = [
                        'shop_id'       => $legacy->shop_id,
                        'business_date' => (string) $legacy->business_date,
                        'metal_type'    => $metal,
                        'legacy_rate'   => $legacyRate,
                    ];
                    continue;
                }

                $delta = abs($newRate - $legacyRate);
                if ($delta > $tolerance) {
                    $mismatches[] = [
                        'shop_id'       => $legacy->shop_id,
                        'business_date' => (string) $legacy->business_date,
                        'metal_type'    => $metal,
                        'legacy_rate'   => $legacyRate,
                        'new_rate'      => $newRate,
                        'delta'         => $delta,
                    ];
                }
            }
        }

        // Reverse direction: find new-table rows that have NO legacy counterpart.
        $orphanQuery = DB::table('shop_daily_metal_rate_entries as new')
            ->leftJoin('shop_daily_metal_rates as legacy', function ($join): void {
                $join->on('legacy.shop_id', '=', 'new.shop_id')
                    ->on('legacy.business_date', '=', 'new.business_date');
            })
            ->whereNull('legacy.id')
            ->whereIn('new.metal_type', ['gold', 'silver']);

        if ($shopFilter !== null) {
            $orphanQuery->where('new.shop_id', $shopFilter);
        }
        if ($dateFilter !== null) {
            $orphanQuery->whereDate('new.business_date', $dateFilter);
        }

        $orphans = $orphanQuery->select([
            'new.shop_id', 'new.business_date', 'new.metal_type', 'new.rate_per_gram',
        ])->get();

        // ── Report ─────────────────────────────────────────────────────
        $this->newLine();
        $this->info(sprintf('Compared %d legacy (shop, date, metal) tuple(s).', $totalChecked));

        if (! empty($mismatches)) {
            $this->error(sprintf('%d MISMATCH(es) detected:', count($mismatches)));
            $this->table(
                ['Shop', 'Business Date', 'Metal', 'Legacy Rate', 'New Rate', 'Delta'],
                array_map(fn ($m) => [
                    $m['shop_id'],
                    $m['business_date'],
                    $m['metal_type'],
                    number_format($m['legacy_rate'], 4),
                    number_format($m['new_rate'], 4),
                    number_format($m['delta'], 4),
                ], $mismatches)
            );
        }

        if (! empty($missingNew)) {
            $this->error(sprintf('%d legacy row(s) MISSING in new table:', count($missingNew)));
            $this->table(
                ['Shop', 'Business Date', 'Metal', 'Legacy Rate'],
                array_map(fn ($m) => [
                    $m['shop_id'], $m['business_date'], $m['metal_type'],
                    number_format($m['legacy_rate'], 4),
                ], $missingNew)
            );
        }

        if ($orphans->isNotEmpty()) {
            $this->error(sprintf('%d new-table row(s) with NO legacy counterpart:', $orphans->count()));
            $this->table(
                ['Shop', 'Business Date', 'Metal', 'New Rate'],
                $orphans->map(fn ($o) => [
                    $o->shop_id, (string) $o->business_date, $o->metal_type,
                    number_format((float) $o->rate_per_gram, 4),
                ])->all()
            );
        }

        if (empty($mismatches) && empty($missingNew) && $orphans->isEmpty()) {
            $this->info('Parity proven across all checked tuples. Tolerance: ' . $tolerance . '/g.');
            return 0;
        }

        $this->warn('Parity violations detected. DO NOT advance to Stage B until resolved.');
        return 1;
    }
}

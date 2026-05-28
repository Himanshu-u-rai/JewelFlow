<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Migration 6/N — Backfill entries from legacy columns.
 *
 * For every existing `shop_daily_metal_rates` row, create two rows in
 * `shop_daily_metal_rate_entries`:
 *   - (shop_id, business_date, 'gold',   rate_per_gram = gold_24k_rate_per_gram)
 *   - (shop_id, business_date, 'silver', rate_per_gram = silver_999_rate_per_gram)
 *
 * Idempotent via ON CONFLICT DO NOTHING (UNIQUE key).
 *
 * Constitutional rule: this is the Stage A foundation. Stage A
 * dual-writes to BOTH the old columns AND this table going forward.
 * Stages B (cutover readers), C (stop old writes), D (drop old cols)
 * are operational milestones gated on parity verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_daily_metal_rates') || ! Schema::hasTable('shop_daily_metal_rate_entries')) {
            return;
        }

        $legacy = DB::table('shop_daily_metal_rates')
            ->select([
                'id', 'shop_id', 'business_date',
                'gold_24k_rate_per_gram', 'silver_999_rate_per_gram',
                'entered_by_user_id', 'entered_at', 'updated_at',
            ])
            ->get();

        $now = now();
        $insertedGold = 0;
        $insertedSilver = 0;

        foreach ($legacy as $row) {
            // Gold entry
            $goldRate = (float) ($row->gold_24k_rate_per_gram ?? 0);
            if ($goldRate > 0) {
                DB::table('shop_daily_metal_rate_entries')->upsert(
                    [[
                        'shop_id'            => (int) $row->shop_id,
                        'business_date'      => $row->business_date,
                        'metal_type'         => 'gold',
                        'rate_per_gram'      => round($goldRate, 4),
                        'source'             => 'manual',
                        'entered_by_user_id' => $row->entered_by_user_id ?: null,
                        'entered_at'         => $row->entered_at ?: $now,
                        'created_at'         => $row->updated_at ?: $now,
                        'updated_at'         => $row->updated_at ?: $now,
                    ]],
                    ['shop_id', 'business_date', 'metal_type'],
                    [] // do not update existing rows on conflict
                );
                $insertedGold++;
            }

            // Silver entry
            $silverRate = (float) ($row->silver_999_rate_per_gram ?? 0);
            if ($silverRate > 0) {
                DB::table('shop_daily_metal_rate_entries')->upsert(
                    [[
                        'shop_id'            => (int) $row->shop_id,
                        'business_date'      => $row->business_date,
                        'metal_type'         => 'silver',
                        'rate_per_gram'      => round($silverRate, 4),
                        'source'             => 'manual',
                        'entered_by_user_id' => $row->entered_by_user_id ?: null,
                        'entered_at'         => $row->entered_at ?: $now,
                        'created_at'         => $row->updated_at ?: $now,
                        'updated_at'         => $row->updated_at ?: $now,
                    ]],
                    ['shop_id', 'business_date', 'metal_type'],
                    []
                );
                $insertedSilver++;
            }
        }

        error_log(sprintf(
            '[Phase1/060000] Backfilled %d legacy rate row(s) → %d gold + %d silver entries.',
            $legacy->count(),
            $insertedGold,
            $insertedSilver
        ));
    }

    public function down(): void
    {
        // No-op. Forward migration is idempotent; reversing would require
        // distinguishing backfilled rows from forward-filled rows, and
        // the table drop in the table-creation migration already removes them.
    }
};

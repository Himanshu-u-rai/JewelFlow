<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Migration 2/N — Seed Tier 1 metals for existing shops.
 *
 * Every shop in `shops` gets two rows in `shop_enabled_metals`:
 *   - (shop_id, 'gold',   enabled=true)
 *   - (shop_id, 'silver', enabled=true)
 *
 * Tier 1 metals are operationally required everywhere; auto-enabling
 * them on every shop preserves backward compatibility — no shop loses
 * gold/silver access during Phase 1 rollout.
 *
 * Tier 2 metals (platinum, copper) are NOT seeded. A shop must explicitly
 * opt in via Settings → Materials.
 *
 * Idempotent: re-runs without creating duplicates (UNIQUE constraint
 * + INSERT … ON CONFLICT DO NOTHING).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_enabled_metals') || ! Schema::hasTable('shops')) {
            return;
        }

        $shopIds = DB::table('shops')->pluck('id')->all();
        $now = now();

        $isPgsql = DB::getDriverName() === 'pgsql';
        $trueLiteral = $isPgsql ? DB::raw('true') : true;

        $rows = [];
        foreach ($shopIds as $shopId) {
            foreach (['gold', 'silver'] as $metal) {
                $rows[] = [
                    'shop_id'    => (int) $shopId,
                    'metal_type' => $metal,
                    // PostgreSQL bulk INSERT rejects PHP true/false on
                    // boolean columns — use DB::raw literal per
                    // CONSTITUTION.md §2 Pattern F4 (PostgreSQL boolean rule).
                    'enabled'    => $trueLiteral,
                    'enabled_at' => $now,
                    'enabled_by_user_id' => null,
                    'notes'      => 'Phase 1 seed — Tier 1 metals auto-enabled for existing shops.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (empty($rows)) {
            return;
        }

        // Postgres-native UPSERT via raw SQL to honor the unique key.
        // Idempotency guarantee: re-running this migration produces no
        // duplicates and modifies no existing rows.
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('shop_enabled_metals')->upsert(
                    $chunk,
                    ['shop_id', 'metal_type'],
                    [] // do not update any columns on conflict — preserve existing rows
                );
            }
        } else {
            // Fallback for sqlite test env, etc.
            foreach ($rows as $row) {
                $exists = DB::table('shop_enabled_metals')
                    ->where('shop_id', $row['shop_id'])
                    ->where('metal_type', $row['metal_type'])
                    ->exists();
                if (! $exists) {
                    DB::table('shop_enabled_metals')->insert($row);
                }
            }
        }

        error_log(sprintf(
            '[Phase1/020000] shop_enabled_metals seeded: %d shop(s), %d row(s).',
            count($shopIds),
            count($rows)
        ));
    }

    public function down(): void
    {
        // Only remove the seed rows (Tier 1 auto-enabled rows with the seed note).
        // Manually-enabled rows added via Settings → Materials are preserved.
        if (! Schema::hasTable('shop_enabled_metals')) {
            return;
        }

        DB::table('shop_enabled_metals')
            ->where('notes', 'Phase 1 seed — Tier 1 metals auto-enabled for existing shops.')
            ->delete();
    }
};

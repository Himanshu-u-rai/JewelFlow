<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A — Migration 2/N — Seed default stone types per shop.
 *
 * Default set (operator-extendable via Settings → Stones later):
 *   diamond, ruby, emerald, sapphire, pearl, moissanite, other.
 *
 * Includes 'other' as the fallback bucket used by the legacy stone_amount
 * backfill (stone_type='other' with no carat_weight) — keeps legacy
 * meaning intact while giving new transactions a typed structure.
 *
 * PostgreSQL boolean rule (CONSTITUTION.md §2 Pattern F4): DB::raw('true')
 * for the is_active default in bulk inserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stone_types') || ! Schema::hasTable('shops')) {
            return;
        }

        $defaults = [
            ['code' => 'diamond',     'label' => 'Diamond',     'sort_order' => 10],
            ['code' => 'ruby',        'label' => 'Ruby',        'sort_order' => 20],
            ['code' => 'emerald',     'label' => 'Emerald',     'sort_order' => 30],
            ['code' => 'sapphire',    'label' => 'Sapphire',    'sort_order' => 40],
            ['code' => 'pearl',       'label' => 'Pearl',       'sort_order' => 50],
            ['code' => 'moissanite',  'label' => 'Moissanite',  'sort_order' => 60],
            ['code' => 'other',       'label' => 'Other',       'sort_order' => 999],
        ];

        $shopIds = DB::table('shops')->pluck('id')->all();
        if (empty($shopIds)) {
            return;
        }

        $isPgsql = DB::getDriverName() === 'pgsql';
        $trueLiteral = $isPgsql ? DB::raw('true') : true;
        $now = now();

        $rows = [];
        foreach ($shopIds as $shopId) {
            foreach ($defaults as $d) {
                $rows[] = [
                    'shop_id'    => (int) $shopId,
                    'code'       => $d['code'],
                    'label'      => $d['label'],
                    'is_active'  => $trueLiteral,
                    'sort_order' => $d['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Idempotent upsert via UNIQUE(shop_id, code).
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('stone_types')->upsert(
                $chunk,
                ['shop_id', 'code'],
                [] // do not overwrite existing rows
            );
        }

        error_log(sprintf(
            '[Phase2A/020000] Seeded stone types: %d shop(s), %d row(s).',
            count($shopIds),
            count($rows)
        ));
    }

    public function down(): void
    {
        // Only remove rows that look like defaults (avoid removing
        // operator-added stone types).
        if (! Schema::hasTable('stone_types')) {
            return;
        }
        DB::table('stone_types')
            ->whereIn('code', ['diamond', 'ruby', 'emerald', 'sapphire', 'pearl', 'moissanite', 'other'])
            ->delete();
    }
};

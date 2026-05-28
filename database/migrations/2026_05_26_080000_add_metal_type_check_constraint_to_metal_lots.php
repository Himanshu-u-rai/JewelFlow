<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 8/9 — Silent Wrongness Elimination
 *
 * Adds a CHECK constraint on `metal_lots.metal_type`:
 *   metal_type IS NULL OR metal_type IN ('gold', 'silver')
 *
 * Same doctrine as Migration 7. NULL is permitted because the column
 * was added later (in 2026_04_25_200000) and legacy lots may not have
 * an explicit metal_type.
 *
 * Pre-validation aborts if any row holds a value outside the allow-list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metal_lots') || ! Schema::hasColumn('metal_lots', 'metal_type')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $violators = DB::select(<<<'SQL'
            SELECT id, shop_id, metal_type, lot_number, source
            FROM metal_lots
            WHERE metal_type IS NOT NULL
              AND metal_type NOT IN ('gold', 'silver')
            ORDER BY shop_id, id
            LIMIT 100
        SQL);

        if (count($violators) > 0) {
            $sample = array_map(
                fn ($r) => sprintf('id=%d shop=%d metal_type=%s lot=%s source=%s',
                    $r->id, $r->shop_id, $r->metal_type, $r->lot_number ?? '-', $r->source),
                array_slice($violators, 0, 10)
            );
            throw new RuntimeException(
                'Phase 0 CHECK constraint on metal_lots.metal_type cannot be applied — '
                . count($violators) . ' violating row(s) found. '
                . 'Sample: ' . implode('; ', $sample)
            );
        }

        $exists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'metal_lots_metal_type_check'"
        );
        if (! $exists) {
            DB::statement(<<<'SQL'
                ALTER TABLE metal_lots
                ADD CONSTRAINT metal_lots_metal_type_check
                CHECK (metal_type IS NULL OR metal_type IN ('gold', 'silver'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE metal_lots DROP CONSTRAINT IF EXISTS metal_lots_metal_type_check');
    }
};

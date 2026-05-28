<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 7/9 — Silent Wrongness Elimination
 *
 * Adds a CHECK constraint on `items.metal_type`:
 *   metal_type IS NULL OR metal_type IN ('gold', 'silver')
 *
 * Today the column is unconstrained VARCHAR(20). A future writer
 * could insert 'platinum', 'copper', 'palladium', or even garbage —
 * and downstream aggregations would silently mishandle the row.
 *
 * Phase 0 hardcodes ('gold', 'silver') because those are the only
 * Tier 1 metals. Phase 1 will widen this constraint to include
 * MetalRegistry-allowed Tier 2 metals when a shop opts in.
 *
 * Pre-validation: counts existing rows that would violate the new
 * constraint. If any are found, the migration ABORTS with the list
 * of violating IDs. No silent acceptance of legacy bad data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('items') || ! Schema::hasColumn('items', 'metal_type')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Pre-validation: refuse to add the constraint if any existing row violates.
        // Constitutional §1 — Trigger Deployment Failure Protocol applies to
        // CHECK constraints too: abort on violation, never coerce.
        $violators = DB::select(<<<'SQL'
            SELECT id, shop_id, metal_type, barcode
            FROM items
            WHERE metal_type IS NOT NULL
              AND metal_type NOT IN ('gold', 'silver')
            ORDER BY shop_id, id
            LIMIT 100
        SQL);

        if (count($violators) > 0) {
            $sample = array_map(
                fn ($r) => sprintf('id=%d shop=%d metal_type=%s barcode=%s', $r->id, $r->shop_id, $r->metal_type, $r->barcode ?? '-'),
                array_slice($violators, 0, 10)
            );
            throw new RuntimeException(
                'Phase 0 CHECK constraint on items.metal_type cannot be applied — '
                . count($violators) . ' violating row(s) found. '
                . 'Each row must be classified to gold/silver or set to NULL before Phase 0 ships. '
                . 'Sample violators: ' . implode('; ', $sample)
            );
        }

        // Idempotent guard.
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'items_metal_type_check'"
        );
        if (! $exists) {
            DB::statement(<<<'SQL'
                ALTER TABLE items
                ADD CONSTRAINT items_metal_type_check
                CHECK (metal_type IS NULL OR metal_type IN ('gold', 'silver'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_metal_type_check');
    }
};

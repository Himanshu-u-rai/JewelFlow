<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 9/9 — Silent Wrongness Elimination
 *
 * Adds a CHECK constraint on `products.metal_type`:
 *   metal_type IS NULL OR metal_type IN ('gold', 'silver')
 *
 * Same doctrine as migrations 7 and 8.
 *
 * Pre-validation aborts if any product row holds a value outside the
 * allow-list. NULL is permitted (legacy products may not have metal_type set).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'metal_type')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $violators = DB::select(<<<'SQL'
            SELECT id, shop_id, name, metal_type
            FROM products
            WHERE metal_type IS NOT NULL
              AND metal_type NOT IN ('gold', 'silver')
            ORDER BY shop_id, id
            LIMIT 100
        SQL);

        if (count($violators) > 0) {
            $sample = array_map(
                fn ($r) => sprintf('id=%d shop=%d metal_type=%s name=%s',
                    $r->id, $r->shop_id, $r->metal_type, $r->name ?? '-'),
                array_slice($violators, 0, 10)
            );
            throw new RuntimeException(
                'Phase 0 CHECK constraint on products.metal_type cannot be applied — '
                . count($violators) . ' violating row(s) found. '
                . 'Sample: ' . implode('; ', $sample)
            );
        }

        $exists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'products_metal_type_check'"
        );
        if (! $exists) {
            DB::statement(<<<'SQL'
                ALTER TABLE products
                ADD CONSTRAINT products_metal_type_check
                CHECK (metal_type IS NULL OR metal_type IN ('gold', 'silver'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_metal_type_check');
    }
};

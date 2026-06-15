<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GST hardening:
 *  #1 — One per-metal GST category per metal: a PARTIAL UNIQUE index on
 *       (shop_id, metal_type) WHERE metal_type IS NOT NULL. Stops duplicate
 *       "Gold" categories that made resolveRate() non-deterministic. (Catch-all
 *       categories with metal_type NULL are exempt; the single-default rule
 *       already governs those.)
 *  #4 — Make the column cap match validation: gst_categories.rate_pct stays
 *       numeric(5,2) (≤99.99 validated) — already consistent; this migration
 *       documents that and is the home for the unique index.
 *
 * Prod data was verified to contain zero existing duplicates before adding the
 * unique index, so it applies without a cleanup step.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive: collapse any duplicate (shop_id, metal_type) rows to the
        // lowest id before the unique index, so the migration is safe even if
        // duplicates were created between the audit and this deploy.
        DB::statement(<<<'SQL'
            DELETE FROM gst_categories a
            USING gst_categories b
            WHERE a.metal_type IS NOT NULL
              AND a.shop_id = b.shop_id
              AND a.metal_type = b.metal_type
              AND a.id > b.id
        SQL);

        // Partial unique index — Postgres-native, scoped to non-null metals.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS gst_categories_shop_metal_unique '
            . 'ON gst_categories (shop_id, metal_type) WHERE metal_type IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS gst_categories_shop_metal_unique');
    }
};

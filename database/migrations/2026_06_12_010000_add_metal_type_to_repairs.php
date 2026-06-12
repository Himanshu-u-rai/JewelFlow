<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair module closure: repairs are a customer-service workflow that can cover
 * any metal, not only gold. Add an explicit metal_type so purity stops being
 * implicitly gold-karat. Existing rows predate the column and were all logged
 * as gold under the old gold-only form, so they backfill to 'gold'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE repairs ADD COLUMN IF NOT EXISTS metal_type VARCHAR(20) NULL");

        // Backfill existing rows: the old form was gold-only, so every existing
        // repair is gold. Use the raw image path? No — metal_type is descriptive.
        DB::statement("UPDATE repairs SET metal_type = 'gold' WHERE metal_type IS NULL");

        DB::statement("ALTER TABLE repairs ADD CONSTRAINT repairs_metal_type_check CHECK (metal_type IN ('gold', 'silver', 'platinum', 'other'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE repairs DROP CONSTRAINT IF EXISTS repairs_metal_type_check');
        DB::statement('ALTER TABLE repairs DROP COLUMN IF EXISTS metal_type');
    }
};

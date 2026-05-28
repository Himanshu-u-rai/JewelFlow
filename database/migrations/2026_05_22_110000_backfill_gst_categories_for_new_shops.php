<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO gst_categories (shop_id, name, rate_pct, metal_type, is_default, created_at, updated_at)
            SELECT
                s.id,
                'Default GST',
                COALESCE(s.gst_rate, 3),
                NULL,
                TRUE,
                NOW(),
                NOW()
            FROM shops s
            WHERE NOT EXISTS (
                SELECT 1 FROM gst_categories gc WHERE gc.shop_id = s.id
            )
        ");
    }

    public function down(): void
    {
        // Non-destructive — no rollback for a safety backfill
    }
};

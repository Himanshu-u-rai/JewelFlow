<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A. Add job_type column with CHECK constraint (default = manufacture, backward-compat)
        DB::statement("ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS job_type VARCHAR(20) NOT NULL DEFAULT 'manufacture'");
        DB::statement("ALTER TABLE job_orders ADD CONSTRAINT job_orders_job_type_check CHECK (job_type IN ('manufacture', 'repair'))");

        // B. Add source_item_id FK — nullable, used only for repair orders where
        //    the karigar receives a specific finished item rather than raw metal.
        DB::statement("ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS source_item_id BIGINT NULL DEFAULT NULL");
        DB::statement("ALTER TABLE job_orders ADD CONSTRAINT job_orders_source_item_id_fk FOREIGN KEY (source_item_id) REFERENCES items(id) ON DELETE SET NULL");

        // C. Add with_karigar to items.status CHECK (repair orders put an item in transit).
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_status_check');
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_status_check CHECK (status IN ('in_stock','sold','returned','melted','transferred','reversed','pending_listing','pending_restock','with_karigar'))");
    }

    public function down(): void
    {
        // Reverse C first (no FK dependency)
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_status_check');
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_status_check CHECK (status IN ('in_stock','sold','returned','melted','transferred','reversed','pending_listing','pending_restock'))");

        // Reverse B
        DB::statement('ALTER TABLE job_orders DROP CONSTRAINT IF EXISTS job_orders_source_item_id_fk');
        DB::statement('ALTER TABLE job_orders DROP COLUMN IF EXISTS source_item_id');

        // Reverse A
        DB::statement('ALTER TABLE job_orders DROP CONSTRAINT IF EXISTS job_orders_job_type_check');
        DB::statement('ALTER TABLE job_orders DROP COLUMN IF EXISTS job_type');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 0 of the Job-Order metal-source model — additive schema only, ZERO
 * behaviour change. Every existing row stays valid; no service reads or writes
 * these columns yet (that lands in later stages).
 *
 * What this adds:
 *  A. job_orders.retained_returned_fine_weight — the 4th residual sink. Metal a
 *     karigar keeps at completion, recorded per-job (the holding lot is the live
 *     balance; this column is the per-job audit of how much was retained vs
 *     wastage). Default 0 = today's behaviour exactly.
 *  B. metal_lots.karigar_id + a partial unique index — lets a lot be a karigar's
 *     holding lot (source='karigar_held'), keyed one-per (shop, karigar, metal,
 *     purity). NULL on every ordinary vault/customer lot.
 *  C. job_order_sources — the AUTHORITATIVE set of metal sources for a job. A
 *     job draws from zero (labor-only) or more legs; each leg names its source
 *     type and the lot/customer it draws from. "metal_source" is intentionally
 *     NOT a stored column — it is derivable from this set, so persisting it
 *     would be a denormalisation that can drift. The set is the single truth.
 *
 * No Constitution Article IX.A trigger is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A. Retained-by-karigar residual sink (per-job audit figure).
        DB::statement('ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS retained_returned_fine_weight DECIMAL(18,6) NOT NULL DEFAULT 0');

        // B. Karigar holding-lot linkage. NULL for all ordinary lots.
        DB::statement('ALTER TABLE metal_lots ADD COLUMN IF NOT EXISTS karigar_id BIGINT NULL DEFAULT NULL');
        DB::statement('ALTER TABLE metal_lots DROP CONSTRAINT IF EXISTS metal_lots_karigar_id_fk');
        DB::statement('ALTER TABLE metal_lots ADD CONSTRAINT metal_lots_karigar_id_fk FOREIGN KEY (karigar_id) REFERENCES karigars(id) ON DELETE RESTRICT');
        // Exactly one holding lot per (shop, karigar, metal, purity); partial so
        // it never constrains ordinary lots. Makes find-or-create race-safe.
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS metal_lots_karigar_held_unique ON metal_lots (shop_id, karigar_id, metal_type, purity) WHERE source = 'karigar_held'");

        // C. The authoritative metal-source set.
        if (! Schema::hasTable('job_order_sources')) {
            Schema::create('job_order_sources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shop_id')->constrained('shops');
                $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
                // vault | karigar_held | customer_advance
                $table->string('source_type', 20);
                // Set for vault / karigar_held legs (the lot drawn from).
                $table->foreignId('metal_lot_id')->nullable()->constrained('metal_lots')->nullOnDelete();
                // Set for customer_advance legs (whose gold this leg consumes).
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->decimal('gross_weight', 18, 6)->nullable();
                $table->decimal('fine_weight', 18, 6);
                $table->decimal('purity', 5, 2)->nullable();
                $table->foreignId('metal_movement_id')->nullable()->constrained('metal_movements')->nullOnDelete();
                $table->timestamps();

                $table->index(['shop_id', 'job_order_id']);
            });
            DB::statement("ALTER TABLE job_order_sources ADD CONSTRAINT job_order_sources_source_type_check CHECK (source_type IN ('vault','karigar_held','customer_advance'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_sources');

        DB::statement('DROP INDEX IF EXISTS metal_lots_karigar_held_unique');
        DB::statement('ALTER TABLE metal_lots DROP CONSTRAINT IF EXISTS metal_lots_karigar_id_fk');
        DB::statement('ALTER TABLE metal_lots DROP COLUMN IF EXISTS karigar_id');

        DB::statement('ALTER TABLE job_orders DROP COLUMN IF EXISTS retained_returned_fine_weight');
    }
};

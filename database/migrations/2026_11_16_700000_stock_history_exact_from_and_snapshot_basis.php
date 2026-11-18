<?php

use App\Services\StockHistory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Honest stock history (owner-approved 2026-11-16). The movement ledger is exact only from deployment; older stock
 * was rebuilt, so year-end snapshots and reports over it must say so.
 *
 * 1. stock_history_starts: one row per shop = the instant exact history began (see App\Services\StockHistory).
 * 2. fiscal_year_snapshots.basis: 'exact' | 'reconstructed', NULLABLE. The table is append-only (trigger #35), so
 *    rows taken before this migration cannot be rewritten and the trigger is NOT touched (Constitution IX.B). A NULL
 *    basis means "taken before this column existed": it is derived at read time from the shop's exact-from instant.
 *    New snapshots always store it explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_history_starts', function ($table) {
            $table->unsignedBigInteger('shop_id')->primary();
            $table->timestamp('exact_from');             // Indian wall clock, like item_movements.occurred_at
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
        });

        // ADD COLUMN does not fire the row triggers; no trigger is disabled or recreated.
        DB::statement('ALTER TABLE fiscal_year_snapshots ADD COLUMN basis varchar(13) NULL');
        DB::statement("ALTER TABLE fiscal_year_snapshots ADD CONSTRAINT fiscal_year_snapshots_basis_check CHECK (basis IS NULL OR basis IN ('exact','reconstructed'))");

        StockHistory::registerExistingShops();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fiscal_year_snapshots DROP CONSTRAINT IF EXISTS fiscal_year_snapshots_basis_check');
        DB::statement('ALTER TABLE fiscal_year_snapshots DROP COLUMN IF EXISTS basis');
        Schema::dropIfExists('stock_history_starts');
    }
};

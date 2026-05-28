<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 1/9 — Silent Wrongness Elimination
 *
 * Adds `metal_type` to `metal_movements` so every gram movement is
 * metal-attributable. Without this column, every aggregation that
 * tries to compute per-metal balance silently sums across metals.
 *
 * Column is nullable in this migration. The companion backfill migration
 * (020000) populates it from joined lots, and migration 060000 (or later
 * in Phase 1) adds the NOT NULL constraint once all rows are populated.
 *
 * `metal_type_was_backfilled` is a constitutional forensics marker.
 * Rows backfilled from FK joins (vs populated forward by the service
 * layer) carry true. This mirrors the `cgst_was_backfilled` doctrine
 * from the GST compliance hardening migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metal_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('metal_movements', 'metal_type')) {
                $table->string('metal_type', 20)->nullable()->after('to_lot_id')
                    ->comment('Metal identity for this movement. Constitutional: every metal_movement is metal-attributable.');
            }

            if (! Schema::hasColumn('metal_movements', 'metal_type_was_backfilled')) {
                $table->boolean('metal_type_was_backfilled')->default(false)->after('metal_type')
                    ->comment('TRUE when populated by Phase 0 backfill (FK-joined). FALSE when set by service layer at insert time.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('metal_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('metal_movements', 'metal_type_was_backfilled')) {
                $table->dropColumn('metal_type_was_backfilled');
            }
            if (Schema::hasColumn('metal_movements', 'metal_type')) {
                $table->dropColumn('metal_type');
            }
        });
    }
};

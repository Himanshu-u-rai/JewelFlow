<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 4/9 — Silent Wrongness Elimination
 *
 * Adds `metal_type` to `invoice_items`. Today the metal context of an
 * invoice line is recovered by joining to `items.metal_type` — but the
 * underlying item's metal_type is not constitutionally locked, which
 * means a future edit to the item's metal_type would silently reinterpret
 * the historical invoice.
 *
 * Snapshot-at-write doctrine: every invoice_item carries its own
 * metal_type copy. After the parent invoice is finalized, the
 * invoice_items_finalized_guard trigger locks this column (extended
 * in migration 060000).
 *
 * The column is nullable in this migration so legacy rows can survive
 * the schema change. The backfill (050000) populates it from the
 * joined items table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_items')) {
            return;
        }

        Schema::table('invoice_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoice_items', 'metal_type')) {
                $table->string('metal_type', 20)->nullable()->after('item_id')
                    ->comment('Metal identity snapshotted at line creation. Locked after invoice finalization.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_items') || ! Schema::hasColumn('invoice_items', 'metal_type')) {
            return;
        }
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropColumn('metal_type');
        });
    }
};

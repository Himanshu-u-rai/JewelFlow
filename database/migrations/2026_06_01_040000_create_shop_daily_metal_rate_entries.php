<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Migration 4/N — Row-per-metal daily rate storage.
 *
 * Today, `shop_daily_metal_rates` stores per-metal rates as dedicated
 * columns (`gold_24k_rate_per_gram`, `silver_999_rate_per_gram`). This
 * schema cannot hold a third metal without a column-add migration, and
 * the column names themselves bake gold/silver assumptions into the
 * schema.
 *
 * `shop_daily_metal_rate_entries` is the constitutionally-clean
 * replacement: one row per (shop, business_date, metal_type). Adding
 * platinum or copper rates is now adding a row, not a migration.
 *
 * Schema invariants:
 *   - UNIQUE(shop_id, business_date, metal_type) — exactly one entry
 *     per metal per business day per shop
 *   - The append-only trigger (next migration) blocks UPDATE/DELETE
 *
 * Dual-write rollout: Stage A is implemented in this batch. Stage A
 * dual-writes to BOTH the legacy `shop_daily_metal_rates` columns AND
 * this new table. Stages B/C/D (cutover, stop-old-writes, drop-old-cols)
 * are operational milestones, not single-PR work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_daily_metal_rate_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('metal_type', 20);

            // Rate in per-gram form for the metal's reference purity
            // (24K for gold, 999 for silver, etc.). Matches the old
            // gold_24k_rate_per_gram / silver_999_rate_per_gram columns.
            $table->decimal('rate_per_gram', 12, 4);

            $table->string('source', 20)->default('manual');  // 'manual' | 'api'
            $table->foreignId('entered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entered_at')->useCurrent();
            $table->timestamps();

            $table->unique(
                ['shop_id', 'business_date', 'metal_type'],
                'shop_daily_metal_rate_entries_unique'
            );

            $table->index(
                ['shop_id', 'business_date'],
                'shop_daily_metal_rate_entries_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_daily_metal_rate_entries');
    }
};

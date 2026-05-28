<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A — Migration 1/N — Per-shop stone type registry.
 *
 * Mirrors the gst_categories / shop_metal_purity_profiles pattern:
 * each shop maintains its own list of stone types. Phase 2A seeds the
 * common ones (diamond, ruby, emerald, sapphire, pearl, moissanite,
 * other) for every existing shop.
 *
 * Stone type is descriptive metadata — it does NOT participate in
 * accounting totals. Per CONSTITUTION.md Article XIV, stone values are
 * manual valuations and are never modified by automated processes;
 * stone_type is just the human-readable label of what kind of stone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stone_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('label', 80);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'code'], 'stone_types_unique');
            $table->index(['shop_id', 'is_active', 'sort_order'], 'stone_types_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stone_types');
    }
};

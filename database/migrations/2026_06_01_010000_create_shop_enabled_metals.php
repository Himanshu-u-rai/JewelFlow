<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Migration 1/N — Material Boundary & MetalRegistry Stabilization
 *
 * Per-shop registry of which metals the shop has explicitly enabled.
 * MetalRegistry reads from this table when answering
 * `isSupportedForShop($shopId, $metalType)` etc.
 *
 * Tier 1 metals (gold, silver) are auto-enabled on shop creation via
 * the seed migration that follows. Tier 2 metals (platinum, copper)
 * require explicit operator opt-in via Settings → Materials, which
 * inserts a row here with an audit log entry.
 *
 * Disabling a metal is permitted only if no active records reference
 * it — checks live in MetalRegistry::disable(). Historical records
 * with the metal remain intact (locked at trigger level).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_enabled_metals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('metal_type', 20);
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'metal_type'], 'shop_enabled_metals_unique');
            $table->index(['shop_id', 'enabled'], 'shop_enabled_metals_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_enabled_metals');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gst_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('rate_pct', 5, 2);
            $table->string('metal_type', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['shop_id', 'metal_type']);
            $table->index(['shop_id', 'is_default']);
        });

        // Seed one default category per existing shop, using the shop's current
        // gst_rate as the rate. This preserves existing behaviour on upgrade.
        // NULL metal_type = applies to all metals (catch-all).
        DB::statement("
            INSERT INTO gst_categories (shop_id, name, rate_pct, metal_type, is_default, created_at, updated_at)
            SELECT
                id,
                'Standard GST',
                COALESCE(gst_rate, 3),
                NULL,
                TRUE,
                NOW(),
                NOW()
            FROM shops
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_categories');
    }
};

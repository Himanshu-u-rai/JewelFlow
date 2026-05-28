<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class B — manual reference-price memo storage (platinum, copper).
 *
 * This is NOT an accounting rate. It is an operator memo: "what I'm selling
 * platinum at this week." Append-only. Never feeds vault, reprice, GST, or
 * reconciliation.
 *
 * Naming is deliberate and forbidden to mirror Class A's vocabulary:
 *   - column `reference_price`  (NOT `rate_per_gram`)
 *   - column `noted_at`         (NOT `business_date`)
 *
 * @see docs/runbooks/pricing-control-plan.md (R2)
 * @see docs/runbooks/material-pricing-classes.md
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shop_metal_reference_prices')) {
            return;
        }

        Schema::create('shop_metal_reference_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('metal_type', 20);
            $table->decimal('reference_price', 12, 2);
            $table->timestamp('noted_at');
            $table->unsignedBigInteger('noted_by_user_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('noted_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['shop_id', 'metal_type', 'noted_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Class-B only: reference prices apply solely to piece-priced metals
            // where purity is a hallmark spec, not accounting truth. Gold/silver
            // (Class A) are constitutionally excluded here.
            DB::statement("
                ALTER TABLE shop_metal_reference_prices
                ADD CONSTRAINT shop_metal_reference_prices_metal_check
                CHECK (metal_type IN ('platinum','copper'))
            ");
            DB::statement("
                ALTER TABLE shop_metal_reference_prices
                ADD CONSTRAINT shop_metal_reference_prices_price_nonneg_check
                CHECK (reference_price >= 0)
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shop_metal_reference_prices DROP CONSTRAINT IF EXISTS shop_metal_reference_prices_metal_check');
            DB::statement('ALTER TABLE shop_metal_reference_prices DROP CONSTRAINT IF EXISTS shop_metal_reference_prices_price_nonneg_check');
        }
        Schema::dropIfExists('shop_metal_reference_prices');
    }
};

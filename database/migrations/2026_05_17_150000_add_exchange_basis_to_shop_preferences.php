<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3B — per-shop default for exchange valuation basis. Most shops will
     * pick one and stick with it (e.g. retailers usually default to today's
     * rate; manufacturers may prefer sale-day rate). The exchange create form
     * defaults to this; the cashier can override per-transaction with approval.
     */
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->string('gold_rate_basis_for_exchange', 32)->default('sale_day_rate')
                  ->after('rounding_method');
        });

        DB::statement(
            "ALTER TABLE shop_preferences ADD CONSTRAINT shop_preferences_exchange_basis_check "
            . "CHECK (gold_rate_basis_for_exchange IN ('sale_day_rate','today_rate'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_preferences DROP CONSTRAINT IF EXISTS shop_preferences_exchange_basis_check');
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn('gold_rate_basis_for_exchange');
        });
    }
};

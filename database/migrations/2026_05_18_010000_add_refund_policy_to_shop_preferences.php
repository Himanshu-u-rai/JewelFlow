<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            // ── Refund policy ───────────────────────────────────────────────
            // All fields are nullable with "full refund" defaults so that
            // existing shops keep their current behaviour until the owner
            // explicitly configures a tighter policy.

            // Component-level refundability flags.
            $table->boolean('refund_making_charges')->nullable()->default(true);
            $table->boolean('refund_stone_charges')->nullable()->default(true);
            $table->boolean('refund_hallmark_charges')->nullable()->default(true);
            $table->boolean('refund_gst')->nullable()->default(true);

            // Deduction percentages.
            $table->decimal('wear_loss_pct', 5, 2)->nullable()->default(0);
            $table->decimal('restocking_fee_pct', 5, 2)->nullable()->default(0);

            // Return/exchange windows. 0 = unlimited.
            $table->unsignedSmallInteger('return_window_days')->nullable()->default(0);
            $table->unsignedSmallInteger('exchange_window_days')->nullable()->default(0);

            // Settlement mode: what refund methods the cashier may offer.
            // cash_only | store_credit_only | cash_or_credit
            $table->string('return_settlement_mode', 20)->nullable()->default('cash_or_credit');

            // Set by SettingsController::updateReturnPolicy the first time the
            // owner saves this form. While null, a banner prompts configuration.
            $table->timestamp('return_policy_configured_at')->nullable()->default(null);
        });
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'refund_making_charges',
                'refund_stone_charges',
                'refund_hallmark_charges',
                'refund_gst',
                'wear_loss_pct',
                'restocking_fee_pct',
                'return_window_days',
                'exchange_window_days',
                'return_settlement_mode',
                'return_policy_configured_at',
            ]);
        });
    }
};

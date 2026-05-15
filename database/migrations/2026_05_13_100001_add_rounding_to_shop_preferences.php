<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            // none | normal | upward | downward
            // Default 'none' preserves current behaviour for existing shops
            // (rollout decision: zero day-1 surprises). Validation lives in
            // the application layer, matching existing patterns (e.g.
            // default_pricing_mode) instead of a DB CHECK constraint.
            $table->string('rounding_method', 10)->nullable()->default('none');

            // NULL = unlimited cap, mirroring how the rest of this table
            // expresses "no limit" (see credit_days = 0 / nullable thresholds).
            $table->decimal('max_manual_discount_percent', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'rounding_method',
                'max_manual_discount_percent',
            ]);
        });
    }
};

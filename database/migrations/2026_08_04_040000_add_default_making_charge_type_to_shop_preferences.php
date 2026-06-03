<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MC-1 foundation: per-shop default making-charge mode (additive, nullable).
 * NULL ⇒ FIXED, so shops that never configure it keep current behaviour.
 * Consumed by the UI default selection in a later phase; inert at MC-1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_preferences', 'default_making_charge_type')) {
                $table->string('default_making_charge_type', 16)->nullable()->after('default_pricing_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            if (Schema::hasColumn('shop_preferences', 'default_making_charge_type')) {
                $table->dropColumn('default_making_charge_type');
            }
        });
    }
};

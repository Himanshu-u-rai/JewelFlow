<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printed-invoice header toggles: show/hide the "Mode" (Sale / Repair Service)
 * and "Time" values. Default true so existing invoices print unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_billing_settings', 'show_mode')) {
                $table->boolean('show_mode')->default(true)->after('show_customer_id_pan');
            }
            if (! Schema::hasColumn('shop_billing_settings', 'show_time')) {
                $table->boolean('show_time')->default(true)->after('show_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            foreach (['show_mode', 'show_time'] as $col) {
                if (Schema::hasColumn('shop_billing_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

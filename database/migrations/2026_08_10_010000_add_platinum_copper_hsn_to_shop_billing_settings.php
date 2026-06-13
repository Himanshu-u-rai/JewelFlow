<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-metal HSN previously covered only gold/silver/diamond; platinum and copper
 * (Tier-2, opt-in via Materials) silently fell back to the gold HSN on invoices,
 * which is wrong for GST. Add their own HSN columns with sensible Indian
 * defaults: platinum jewellery 7115, refined copper / copper articles 7403.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_billing_settings', 'hsn_platinum')) {
                $table->string('hsn_platinum', 20)->nullable()->default('7115')->after('hsn_diamond');
            }
            if (! Schema::hasColumn('shop_billing_settings', 'hsn_copper')) {
                $table->string('hsn_copper', 20)->nullable()->default('7403')->after('hsn_platinum');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            foreach (['hsn_platinum', 'hsn_copper'] as $col) {
                if (Schema::hasColumn('shop_billing_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

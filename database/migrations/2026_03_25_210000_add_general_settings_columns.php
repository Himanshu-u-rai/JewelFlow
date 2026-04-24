<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── shops ──────────────────────────────────────────────────────────
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'shop_whatsapp')) {
                $table->string('shop_whatsapp', 20)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('shops', 'shop_email')) {
                $table->string('shop_email', 100)->nullable()->after('shop_whatsapp');
            }
            if (!Schema::hasColumn('shops', 'established_year')) {
                $table->unsignedSmallInteger('established_year')->nullable()->after('shop_email');
            }
            if (!Schema::hasColumn('shops', 'shop_registration_number')) {
                $table->string('shop_registration_number', 100)->nullable()->after('established_year');
            }
        });

        // ── shop_preferences ───────────────────────────────────────────────
        Schema::table('shop_preferences', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_preferences', 'default_pricing_mode')) {
                $table->string('default_pricing_mode', 20)->default('gst_exclusive')->after('language');
            }
            if (!Schema::hasColumn('shop_preferences', 'default_payment_mode')) {
                $table->string('default_payment_mode', 20)->default('cash')->after('default_pricing_mode');
            }
            if (!Schema::hasColumn('shop_preferences', 'auto_logout_minutes')) {
                $table->unsignedSmallInteger('auto_logout_minutes')->default(0)->after('default_payment_mode');
            }
            if (!Schema::hasColumn('shop_preferences', 'loyalty_welcome_bonus')) {
                $table->unsignedInteger('loyalty_welcome_bonus')->default(0)->after('auto_logout_minutes');
            }
            if (!Schema::hasColumn('shop_preferences', 'credit_days')) {
                $table->unsignedSmallInteger('credit_days')->default(0)->after('loyalty_welcome_bonus');
            }
            if (!Schema::hasColumn('shop_preferences', 'barcode_prefix')) {
                $table->string('barcode_prefix', 20)->nullable()->after('credit_days');
            }
        });

        // ── shop_rules ─────────────────────────────────────────────────────
        Schema::table('shop_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_rules', 'gst_rate_gold')) {
                $table->decimal('gst_rate_gold', 5, 2)->nullable()->after('rounding_precision');
            }
            if (!Schema::hasColumn('shop_rules', 'gst_rate_silver')) {
                $table->decimal('gst_rate_silver', 5, 2)->nullable()->after('gst_rate_gold');
            }
            if (!Schema::hasColumn('shop_rules', 'gst_rate_diamond')) {
                $table->decimal('gst_rate_diamond', 5, 2)->nullable()->after('gst_rate_silver');
            }
            if (!Schema::hasColumn('shop_rules', 'wastage_rounding')) {
                $table->string('wastage_rounding', 10)->default('0.001')->after('gst_rate_diamond');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['shop_whatsapp', 'shop_email', 'established_year', 'shop_registration_number']);
        });
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn(['default_pricing_mode', 'default_payment_mode', 'auto_logout_minutes', 'loyalty_welcome_bonus', 'credit_days', 'barcode_prefix']);
        });
        Schema::table('shop_rules', function (Blueprint $table) {
            $table->dropColumn(['gst_rate_gold', 'gst_rate_silver', 'gst_rate_diamond', 'wastage_rounding']);
        });
    }
};

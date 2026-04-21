<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            // ── Branding ──────────────────────────────────────────────────
            if (!Schema::hasColumn('shop_billing_settings', 'theme_color')) {
                $table->string('theme_color', 7)->default('#111111')->after('show_bis_logo');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'font_size')) {
                $table->string('font_size', 10)->default('normal')->after('theme_color');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'shop_subtitle')) {
                $table->string('shop_subtitle', 100)->nullable()->after('font_size');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'custom_tagline')) {
                $table->string('custom_tagline', 150)->nullable()->after('shop_subtitle');
            }
            // ── Invoice copy ──────────────────────────────────────────────
            if (!Schema::hasColumn('shop_billing_settings', 'invoice_copy_label')) {
                $table->string('invoice_copy_label', 20)->default('Original')->after('custom_tagline');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'copy_count')) {
                $table->unsignedTinyInteger('copy_count')->default(1)->after('invoice_copy_label');
            }
            // ── Invoice number ────────────────────────────────────────────
            if (!Schema::hasColumn('shop_billing_settings', 'invoice_suffix')) {
                $table->string('invoice_suffix', 20)->nullable()->after('copy_count');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'year_reset')) {
                $table->boolean('year_reset')->default(false)->after('invoice_suffix');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'current_fiscal_year')) {
                $table->string('current_fiscal_year', 10)->nullable()->after('year_reset');
            }
            // ── Column visibility ─────────────────────────────────────────
            if (!Schema::hasColumn('shop_billing_settings', 'show_huid')) {
                $table->boolean('show_huid')->default(true)->after('current_fiscal_year');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'show_stone_columns')) {
                $table->boolean('show_stone_columns')->default(true)->after('show_huid');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'show_purity')) {
                $table->boolean('show_purity')->default(true)->after('show_stone_columns');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'show_gstin')) {
                $table->boolean('show_gstin')->default(true)->after('show_purity');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'show_customer_address')) {
                $table->boolean('show_customer_address')->default(true)->after('show_gstin');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'show_customer_id_pan')) {
                $table->boolean('show_customer_id_pan')->default(true)->after('show_customer_address');
            }
            // ── Tax ───────────────────────────────────────────────────────
            if (!Schema::hasColumn('shop_billing_settings', 'igst_mode')) {
                $table->boolean('igst_mode')->default(false)->after('show_customer_id_pan');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'hsn_gold')) {
                $table->string('hsn_gold', 20)->default('7113')->after('igst_mode');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'hsn_silver')) {
                $table->string('hsn_silver', 20)->default('7113')->after('hsn_gold');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'hsn_diamond')) {
                $table->string('hsn_diamond', 20)->default('7114')->after('hsn_silver');
            }
            // ── Footer / print ────────────────────────────────────────────
            if (!Schema::hasColumn('shop_billing_settings', 'second_signature_label')) {
                $table->string('second_signature_label', 100)->nullable()->after('hsn_diamond');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'paper_size')) {
                $table->string('paper_size', 10)->default('a4')->after('second_signature_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'theme_color', 'font_size', 'shop_subtitle', 'custom_tagline',
                'invoice_copy_label', 'copy_count', 'invoice_suffix', 'year_reset',
                'current_fiscal_year', 'show_huid', 'show_stone_columns', 'show_purity',
                'show_gstin', 'show_customer_address', 'show_customer_id_pan',
                'igst_mode', 'hsn_gold', 'hsn_silver', 'hsn_diamond',
                'second_signature_label', 'paper_size',
            ]);
        });
    }
};

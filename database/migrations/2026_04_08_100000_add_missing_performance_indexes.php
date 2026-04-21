<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customers: frequently filtered/sorted by shop + created_at
        Schema::table('customers', function (Blueprint $table) {
            $table->index(['shop_id', 'created_at'], 'customers_shop_created_at_idx');
        });

        // Items: POS and inventory queries filter by shop + status, sort by created_at
        Schema::table('items', function (Blueprint $table) {
            $table->index(['shop_id', 'status', 'created_at'], 'items_shop_status_created_at_idx');
        });

        // Invoice payments: views group by invoice + mode for payment breakdowns
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->index(['invoice_id', 'mode'], 'invoice_payments_invoice_mode_idx');
        });

        // Scheme enrollments: service queries check active enrollments per customer
        Schema::table('scheme_enrollments', function (Blueprint $table) {
            $table->index(['shop_id', 'customer_id', 'status'], 'scheme_enrollments_shop_customer_status_idx');
        });

        // Invoices: status filter queries (finalized, cancelled, etc.)
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['shop_id', 'status'], 'invoices_shop_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_shop_created_at_idx');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_shop_status_created_at_idx');
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropIndex('invoice_payments_invoice_mode_idx');
        });

        Schema::table('scheme_enrollments', function (Blueprint $table) {
            $table->dropIndex('scheme_enrollments_shop_customer_status_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_shop_status_idx');
        });
    }
};

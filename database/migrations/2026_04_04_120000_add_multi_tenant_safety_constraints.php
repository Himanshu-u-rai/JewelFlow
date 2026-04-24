<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prevent duplicate invoice numbers within a shop
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['shop_id', 'invoice_number'], 'invoices_shop_invoice_number_unique');
        });

        // Prevent duplicate quick bill numbers within a shop
        Schema::table('quick_bills', function (Blueprint $table) {
            $table->unique(['shop_id', 'bill_number'], 'quick_bills_shop_bill_number_unique');
        });

        // Restrict item status to valid values
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_status_check CHECK (status IN ('in_stock', 'sold', 'returned', 'melted', 'transferred'))");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_shop_invoice_number_unique');
        });

        Schema::table('quick_bills', function (Blueprint $table) {
            $table->dropUnique('quick_bills_shop_bill_number_unique');
        });

        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_status_check');
    }
};

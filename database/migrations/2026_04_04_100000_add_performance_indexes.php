<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->index(['shop_id', 'due_date'], 'repairs_shop_due_date_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['shop_id', 'created_at'], 'invoices_shop_created_at_idx');
        });

        Schema::table('quick_bills', function (Blueprint $table) {
            $table->index(['shop_id', 'bill_date'], 'quick_bills_shop_bill_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->dropIndex('repairs_shop_due_date_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_shop_created_at_idx');
        });

        Schema::table('quick_bills', function (Blueprint $table) {
            $table->dropIndex('quick_bills_shop_bill_date_idx');
        });
    }
};

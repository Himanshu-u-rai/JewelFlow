<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->nullable()->after('shop_id');
            $table->foreign('payment_method_id')->references('id')->on('shop_payment_methods')->nullOnDelete();
        });

        // Extend mode check constraint to include wallet
        DB::statement("ALTER TABLE invoice_payments DROP CONSTRAINT IF EXISTS invoice_payments_mode_check");
        DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_mode_check CHECK (mode IN ('cash','upi','bank','wallet','old_gold','old_silver','other','emi','scheme'))");
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn('payment_method_id');
        });

        DB::statement("ALTER TABLE invoice_payments DROP CONSTRAINT IF EXISTS invoice_payments_mode_check");
        DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_mode_check CHECK (mode IN ('cash','upi','bank','old_gold','old_silver','other','emi','scheme'))");
    }
};

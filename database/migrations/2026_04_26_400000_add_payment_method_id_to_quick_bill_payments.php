<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_bill_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->foreign('payment_method_id')
                ->references('id')
                ->on('shop_payment_methods')
                ->nullOnDelete();
            $table->index(['shop_id', 'payment_method_id'], 'quick_bill_payments_shop_method_idx');
        });
    }

    public function down(): void
    {
        Schema::table('quick_bill_payments', function (Blueprint $table) {
            $table->dropIndex('quick_bill_payments_shop_method_idx');
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn('payment_method_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each EMI installment payment to the specific UPI / bank / wallet account
 * it was collected through (a ShopPaymentMethod) — the same account linkage POS
 * and invoice payments already have. Nullable: cash/other payments carry none,
 * and all historical rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->nullable()->after('payment_method');
            $table->foreign('payment_method_id')->references('id')->on('shop_payment_methods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('installment_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn('payment_method_id');
        });
    }
};

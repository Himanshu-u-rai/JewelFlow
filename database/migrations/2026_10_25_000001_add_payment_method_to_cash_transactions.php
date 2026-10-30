<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the shop's accounts a manual cash book entry went through (UPI,
 * bank, wallet), as invoice, quick bill and karigar payments already record.
 *
 * Both columns are nullable (CONSTITUTION §4 Rule 1): older rows truthfully
 * carry no account and are never backfilled. The foreign key RESTRICTS deletes
 * instead of nulling: cash_transactions is append-only, so ON DELETE SET NULL
 * would be an UPDATE the triggers refuse. PaymentMethodController::destroy
 * refuses a used account first, with its usual message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('payment_method_label_snapshot', 255)->nullable();
            $table->foreign('payment_method_id')->references('id')->on('shop_payment_methods')->restrictOnDelete();
            $table->index(['shop_id', 'payment_method_id'], 'cash_transactions_shop_method_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex('cash_transactions_shop_method_idx');
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn(['payment_method_id', 'payment_method_label_snapshot']);
        });
    }
};

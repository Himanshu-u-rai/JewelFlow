<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();

            $table->string('type', 20);   // cash | upi | bank | wallet | other | emi
            $table->string('name', 100);  // e.g. "PhonePe", "HDFC Savings"

            // UPI
            $table->string('upi_id', 100)->nullable();

            // Bank
            $table->string('bank_name', 100)->nullable();
            $table->string('account_holder', 100)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('ifsc_code', 20)->nullable();
            $table->string('account_type', 20)->nullable(); // current | savings | overdraft
            $table->string('branch', 100)->nullable();

            // Wallet
            $table->string('wallet_id', 100)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['shop_id', 'type']);
            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_payment_methods');
    }
};

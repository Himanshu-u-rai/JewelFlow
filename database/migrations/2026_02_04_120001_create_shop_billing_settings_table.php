<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * shop_billing_settings: Invoice rendering rules
     * Affects: How invoices look (not calculations)
     */
    public function up(): void
    {
        Schema::create('shop_billing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->onDelete('cascade');
            $table->string('invoice_prefix')->default('INV-');
            $table->integer('invoice_start_number')->default(1001);
            $table->text('terms_and_conditions')->nullable();
            $table->text('bank_details')->nullable();
            $table->string('upi_id')->nullable();
            $table->timestamps();

            $table->unique('shop_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_billing_settings');
    }
};

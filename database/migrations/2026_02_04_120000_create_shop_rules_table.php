<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * shop_rules: Gold & POS calculation rules
     * Affects: POS price calculation, exchange math, buyback logic, ledger fine gold math
     */
    public function up(): void
    {
        Schema::create('shop_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->onDelete('cascade');
            $table->string('default_purity')->default('22K'); // 24K, 22K, 18K, etc.
            $table->enum('default_making_type', ['per_gram', 'percent'])->default('per_gram');
            $table->decimal('default_making_value', 10, 2)->default(0);
            $table->decimal('test_loss_percent', 5, 2)->default(0); // Loss during purity testing
            $table->decimal('buyback_percent', 5, 2)->default(100); // % of gold rate for buybacks
            $table->integer('rounding_precision')->default(2); // Decimal places for calculations
            $table->timestamps();

            $table->unique('shop_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_rules');
    }
};

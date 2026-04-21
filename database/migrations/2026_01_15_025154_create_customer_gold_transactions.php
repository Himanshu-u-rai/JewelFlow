<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_gold_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained('shops');
            $table->foreignId('customer_id')->constrained('customers');

            $table->decimal('fine_gold', 18, 6); // +credit, -debit

            $table->string('type'); // advance, adjust, refund
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_gold_transactions');
    }
};

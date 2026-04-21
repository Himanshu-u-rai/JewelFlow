<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * shop_preferences: UI & behavior preferences
     * Affects: Display, alerts, reports formatting
     */
    public function up(): void
    {
        Schema::create('shop_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->onDelete('cascade');
            $table->enum('weight_unit', ['grams', 'tola'])->default('grams');
            $table->string('date_format')->default('d/m/Y');
            $table->string('currency_symbol')->default('₹');
            $table->integer('low_stock_threshold')->default(5);
            $table->timestamps();

            $table->unique('shop_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_preferences');
    }
};

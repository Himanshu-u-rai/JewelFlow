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
        Schema::create('repairs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained('shops');
            $table->foreignId('customer_id')->constrained('customers');

            $table->string('item_description');
            $table->decimal('gross_weight', 18, 6);
            $table->decimal('purity', 5, 2);

            $table->decimal('estimated_cost', 18, 2)->nullable();
            $table->decimal('final_cost', 18, 2)->nullable();

            $table->string('status'); 
            // received, in_repair, ready, delivered

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repairs');
    }
};

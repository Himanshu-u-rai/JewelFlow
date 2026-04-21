<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('metal_lots', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // buyback, purchase, melt, opening
            $table->decimal('purity', 5, 2); // e.g. 22.00, 18.00
            $table->decimal('fine_weight_total', 18, 6); // pure gold in grams
            $table->decimal('fine_weight_remaining', 18, 6);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metal_lots');
    }
};

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
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            $table->string('barcode')->unique();
            $table->string('design');
            $table->string('category'); // ring, chain, bangle, etc

            $table->decimal('gross_weight', 18, 6);
            $table->decimal('stone_weight', 18, 6)->default(0);
            $table->decimal('net_metal_weight', 18, 6);

            $table->decimal('purity', 5, 2); // 22.00, 18.00

            $table->foreignId('metal_lot_id')->constrained('metal_lots');

            $table->string('status'); // in_stock, sold, repair, melted

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};

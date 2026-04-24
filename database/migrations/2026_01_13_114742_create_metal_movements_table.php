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
        Schema::create('metal_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('from_lot_id')->nullable()->constrained('metal_lots');
            $table->foreignId('to_lot_id')->nullable()->constrained('metal_lots');

            $table->decimal('fine_weight', 18, 6);
            $table->string('type'); // sale, buyback, melt, repair, manufacture
            $table->string('reference_type')->nullable(); // invoice, job, melt
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metal_movements');
    }
};

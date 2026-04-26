<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_issuances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('job_order_id');
            $table->unsignedBigInteger('metal_lot_id')->nullable();
            $table->unsignedBigInteger('metal_movement_id')->nullable();

            $table->decimal('gross_weight', 18, 6);
            $table->decimal('fine_weight', 18, 6);
            $table->decimal('purity', 5, 2);
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('job_order_id')->references('id')->on('job_orders')->cascadeOnDelete();
            $table->foreign('metal_lot_id')->references('id')->on('metal_lots')->nullOnDelete();
            $table->foreign('metal_movement_id')->references('id')->on('metal_movements')->nullOnDelete();

            $table->index(['shop_id', 'job_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_issuances');
    }
};

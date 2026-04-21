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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('name');
            $table->string('design_code');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('sub_category_id');
            $table->integer('default_purity')->nullable();
            $table->decimal('approx_weight', 10, 3)->nullable();
            $table->decimal('default_making', 10, 2)->nullable();
            $table->decimal('default_stone', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'design_code']);
            $table->index(['shop_id', 'design_code']);

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('restrict');
            $table->foreign('sub_category_id')->references('id')->on('sub_categories')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

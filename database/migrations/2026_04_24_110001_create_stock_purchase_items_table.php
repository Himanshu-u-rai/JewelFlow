<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_purchase_id')->index();
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('item_id')->nullable()->index();

            $table->enum('line_type', ['ornament', 'bullion_for_sale', 'bullion_reserve'])->default('ornament');

            $table->string('barcode', 100)->nullable();
            $table->string('design', 255)->nullable();
            $table->string('category', 255)->nullable();
            $table->string('sub_category', 255)->nullable();
            $table->enum('metal_type', ['gold', 'silver'])->default('gold');
            $table->decimal('purity', 8, 3)->default(0);
            $table->decimal('gross_weight', 12, 3)->default(0);
            $table->decimal('stone_weight', 12, 3)->default(0);
            $table->decimal('net_metal_weight', 12, 6)->default(0);
            $table->string('huid', 30)->nullable();
            $table->date('hallmark_date')->nullable();
            $table->string('hsn_code', 20)->nullable();

            $table->decimal('making_charges', 12, 2)->default(0);
            $table->decimal('stone_charges', 12, 2)->default(0);
            $table->decimal('hallmark_charges', 12, 2)->default(0);
            $table->decimal('rhodium_charges', 12, 2)->default(0);
            $table->decimal('other_charges', 12, 2)->default(0);

            $table->decimal('purchase_rate_per_gram', 12, 4)->default(0);
            $table->decimal('purchase_line_amount', 12, 2)->default(0);

            $table->string('notes', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('stock_purchase_id')->references('id')->on('stock_purchases')->cascadeOnDelete();
            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_purchase_items');
    }
};

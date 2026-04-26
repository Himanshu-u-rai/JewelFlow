<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('job_order_receipt_id');
            $table->unsignedBigInteger('item_id')->nullable();

            $table->string('description', 255);
            $table->string('hsn_code', 20)->nullable();
            $table->unsignedInteger('pieces')->default(1);

            $table->decimal('gross_weight', 18, 6)->default(0);
            $table->decimal('stone_weight', 18, 6)->default(0);
            $table->decimal('net_weight', 18, 6)->default(0);
            $table->decimal('purity', 5, 2)->default(0);
            $table->decimal('fine_weight', 18, 6)->default(0);
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('job_order_receipt_id')->references('id')->on('job_order_receipts')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();

            $table->index(['shop_id', 'job_order_receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_receipt_items');
    }
};

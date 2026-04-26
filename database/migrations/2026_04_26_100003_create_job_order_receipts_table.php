<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('job_order_id');

            $table->string('receipt_number', 30);
            $table->date('receipt_date');

            $table->unsignedInteger('total_pieces')->default(0);
            $table->decimal('total_gross_weight', 18, 6)->default(0);
            $table->decimal('total_stone_weight', 18, 6)->default(0);
            $table->decimal('total_net_weight', 18, 6)->default(0);
            $table->decimal('total_fine_weight', 18, 6)->default(0);

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('job_order_id')->references('id')->on('job_orders')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['shop_id', 'job_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_receipts');
    }
};

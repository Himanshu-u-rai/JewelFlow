<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karigar_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('karigar_id');
            $table->unsignedBigInteger('karigar_invoice_id')->nullable();
            $table->unsignedBigInteger('payment_method_id')->nullable();

            $table->decimal('amount', 14, 2);
            $table->string('mode', 20);
            $table->string('reference', 255)->nullable();
            $table->date('paid_on');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('karigar_id')->references('id')->on('karigars')->restrictOnDelete();
            $table->foreign('karigar_invoice_id')->references('id')->on('karigar_invoices')->nullOnDelete();
            $table->foreign('payment_method_id')->references('id')->on('shop_payment_methods')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['shop_id', 'karigar_id']);
            $table->index(['shop_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karigar_payments');
    }
};

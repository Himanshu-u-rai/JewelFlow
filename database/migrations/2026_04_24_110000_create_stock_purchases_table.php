<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('supplier_name', 255)->nullable();
            $table->string('supplier_gstin', 20)->nullable();
            $table->string('purchase_number', 30)->index();
            $table->string('invoice_number', 100)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('purchase_date');
            $table->enum('status', ['draft', 'confirmed'])->default('draft')->index();
            $table->string('invoice_image')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('labour_discount', 12, 2)->default(0);
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('cgst_rate', 5, 2)->default(0);
            $table->decimal('cgst_amount', 12, 2)->default(0);
            $table->decimal('sgst_rate', 5, 2)->default(0);
            $table->decimal('sgst_amount', 12, 2)->default(0);
            $table->decimal('igst_rate', 5, 2)->default(0);
            $table->decimal('igst_amount', 12, 2)->default(0);
            $table->decimal('tcs_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('irn_number', 100)->nullable();
            $table->string('ack_number', 100)->nullable();
            $table->unsignedBigInteger('entered_by_user_id');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
            $table->foreign('entered_by_user_id')->references('id')->on('users');
            $table->foreign('confirmed_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_purchases');
    }
};

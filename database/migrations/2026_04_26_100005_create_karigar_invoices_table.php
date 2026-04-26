<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karigar_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('karigar_id');
            $table->unsignedBigInteger('job_order_id')->nullable();

            $table->string('mode', 15)->default('purchase');
            $table->string('karigar_invoice_number', 100);
            $table->date('karigar_invoice_date');

            $table->string('state_code', 5)->nullable();
            $table->boolean('is_interstate')->default(false);

            $table->unsignedInteger('total_pieces')->default(0);
            $table->decimal('total_gross_weight', 18, 6)->default(0);
            $table->decimal('total_stone_weight', 18, 6)->default(0);
            $table->decimal('total_net_weight', 18, 6)->default(0);

            $table->decimal('total_metal_amount', 14, 2)->default(0);
            $table->decimal('total_extra_amount', 14, 2)->default(0);
            $table->decimal('total_making_amount', 14, 2)->default(0);
            $table->decimal('total_wastage_amount', 14, 2)->default(0);
            $table->decimal('total_before_tax', 14, 2)->default(0);

            $table->decimal('cgst_rate', 5, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_rate', 5, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_rate', 5, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_after_tax', 14, 2)->default(0);

            $table->string('amount_in_words', 255)->nullable();
            $table->string('tax_amount_in_words', 255)->nullable();
            $table->string('jurisdiction', 100)->nullable();
            $table->string('payment_terms', 255)->nullable();

            $table->string('payment_status', 15)->default('unpaid');
            $table->decimal('amount_paid', 14, 2)->default(0);

            $table->string('invoice_file_path', 500)->nullable();
            $table->json('discrepancy_flags')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('karigar_id')->references('id')->on('karigars')->restrictOnDelete();
            $table->foreign('job_order_id')->references('id')->on('job_orders')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['shop_id', 'payment_status']);
            $table->index(['shop_id', 'karigar_id']);
            $table->unique(['shop_id', 'karigar_id', 'karigar_invoice_number'], 'karigar_invoices_unique_per_karigar');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karigar_invoices');
    }
};

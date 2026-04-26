<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karigar_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('karigar_invoice_id');
            $table->unsignedBigInteger('linked_receipt_item_id')->nullable();

            $table->string('description', 255);
            $table->string('hsn_code', 20)->nullable();
            $table->unsignedInteger('pieces')->default(1);

            $table->decimal('gross_weight', 18, 6)->default(0);
            $table->decimal('stone_weight', 18, 6)->default(0);
            $table->decimal('net_weight', 18, 6)->default(0);
            $table->decimal('purity', 5, 2)->default(0);

            $table->decimal('rate_per_gram', 12, 2)->default(0);
            $table->decimal('metal_amount', 14, 2)->default(0);
            $table->decimal('making_charge', 14, 2)->default(0);
            $table->decimal('wastage_charge', 14, 2)->default(0);
            $table->decimal('extra_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);

            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->foreign('karigar_invoice_id')->references('id')->on('karigar_invoices')->cascadeOnDelete();
            $table->foreign('linked_receipt_item_id')->references('id')->on('job_order_receipt_items')->nullOnDelete();

            $table->index('karigar_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karigar_invoice_lines');
    }
};

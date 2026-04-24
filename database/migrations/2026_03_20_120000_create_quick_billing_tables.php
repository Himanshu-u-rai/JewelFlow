<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('bill_sequence');
            $table->string('bill_number', 40);
            $table->string('status', 20)->default('draft');
            $table->date('bill_date');
            $table->string('customer_name')->nullable();
            $table->string('customer_mobile', 20)->nullable();
            $table->text('customer_address')->nullable();
            $table->string('pricing_mode', 20)->default('gst_exclusive');
            $table->decimal('gst_rate', 5, 2)->default(3);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type', 20)->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('round_off', 12, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('cgst_amount', 12, 2)->default(0);
            $table->decimal('sgst_amount', 12, 2)->default(0);
            $table->decimal('igst_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('due_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('shop_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'bill_sequence']);
            $table->unique(['shop_id', 'bill_number']);
            $table->index(['shop_id', 'status', 'bill_date']);
        });

        Schema::create('quick_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('quick_bill_id')->constrained('quick_bills')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('description');
            $table->string('hsn_code', 40)->nullable();
            $table->string('metal_type', 30)->nullable();
            $table->string('purity', 30)->nullable();
            $table->unsignedInteger('pcs')->default(1);
            $table->decimal('gross_weight', 10, 3)->default(0);
            $table->decimal('stone_weight', 10, 3)->default(0);
            $table->decimal('net_weight', 10, 3)->default(0);
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('making_charge', 12, 2)->default(0);
            $table->decimal('stone_charge', 12, 2)->default(0);
            $table->decimal('wastage_percent', 6, 2)->default(0);
            $table->decimal('line_discount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['shop_id', 'quick_bill_id']);
        });

        Schema::create('quick_bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('quick_bill_id')->constrained('quick_bills')->cascadeOnDelete();
            $table->string('payment_mode', 30);
            $table->string('reference_no', 100)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'quick_bill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_bill_payments');
        Schema::dropIfExists('quick_bill_items');
        Schema::dropIfExists('quick_bills');
    }
};

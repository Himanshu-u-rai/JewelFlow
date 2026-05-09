<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global platform-level sequential counter (not shop-scoped)
        Schema::create('platform_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('counter_key', 40)->unique();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });

        Schema::create('platform_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('shop_subscription_id')
                ->nullable()
                ->constrained('shop_subscriptions')
                ->nullOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('invoice_number', 50)->unique();
            $table->unsignedInteger('invoice_sequence');
            $table->string('billing_cycle', 10); // monthly | yearly
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->decimal('amount_before_tax', 14, 2);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->string('razorpay_payment_id', 255)->nullable()->index();
            $table->string('razorpay_order_id', 255)->nullable();
            $table->string('payment_method', 30)->default('razorpay'); // razorpay | manual | free
            $table->string('status', 20)->default('issued');           // issued | cancelled
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('issued_at');
            $table->foreignId('created_by_admin_id')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
        Schema::dropIfExists('platform_counters');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical opening receivable/payable for a customer's MONEY balance — the one
 * genuine ledger gap (JewelFlow tracks unpaid invoices, not a free-standing
 * customer money balance). Written once at batch lock; the customer ledger view
 * reads this as a single "Opening Balance" line before invoice/payment history.
 * No fake invoice is ever created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('onboarding_batch_id')->constrained('onboarding_batches')->cascadeOnDelete();
            $table->string('direction'); // receivable (customer owes shop) | payable (shop owes customer)
            $table->decimal('amount', 18, 2);
            $table->date('as_of_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_opening_balances');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical opening payable/receivable for a supplier (vendor). The ongoing
 * supplier ledger is out of scope — this holds the opening figure only, read by
 * a minimal supplier-outstanding view. Written once at batch lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('onboarding_batch_id')->constrained('onboarding_batches')->cascadeOnDelete();
            $table->string('direction'); // payable (shop owes supplier) | receivable (supplier owes shop)
            $table->decimal('amount', 18, 2);
            $table->date('as_of_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_opening_balances');
    }
};

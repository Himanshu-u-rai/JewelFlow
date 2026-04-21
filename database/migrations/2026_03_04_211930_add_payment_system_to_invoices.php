<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add discount & round_off to invoices
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('discount', 12, 2)->default(0)->after('wastage_charge');
            $table->decimal('round_off', 12, 2)->default(0)->after('discount');
        });

        // Split-payment ledger: one row per payment mode per invoice
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('shop_id')->index();
            $table->string('mode', 30);
            // modes: cash, upi, bank, old_gold, old_silver, other
            $table->decimal('amount', 14, 2)->default(0);

            // For old metal payments
            $table->string('metal_type', 20)->nullable();   // gold, silver
            $table->decimal('metal_gross_weight', 10, 3)->nullable();
            $table->decimal('metal_purity', 6, 2)->nullable(); // karat for gold, millesimal for silver
            $table->decimal('metal_test_loss', 5, 2)->nullable();
            $table->decimal('metal_fine_weight', 10, 3)->nullable();
            $table->decimal('metal_rate_per_gram', 12, 2)->nullable();

            // For online payments
            $table->string('reference', 100)->nullable(); // UPI ref / bank txn id

            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['discount', 'round_off']);
        });
    }
};

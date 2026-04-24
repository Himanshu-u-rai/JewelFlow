<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhiran_cash_entries', function (Blueprint $table): void {
            // One cash entry per payment. Guarantees idempotency: a retried
            // recordPayment cannot double-post to the cash ledger.
            $table->unique('dhiran_payment_id', 'dhiran_cash_entries_payment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('dhiran_cash_entries', function (Blueprint $table): void {
            $table->dropUnique('dhiran_cash_entries_payment_id_unique');
        });
    }
};

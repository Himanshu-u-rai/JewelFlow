<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acknowledged_vault_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('reconciliation_run_id');
            $table->unsignedBigInteger('acknowledged_by_user_id');
            $table->timestamp('acknowledged_at');
            $table->text('acknowledgement_reason');
            $table->decimal('discrepancy_amount_at_ack', 12, 4);
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('reconciliation_run_id')->references('id')->on('vault_reconciliation_runs');
            $table->foreign('acknowledged_by_user_id')->references('id')->on('users')->nullOnDelete();

            // One acknowledgement per run per shop
            $table->unique(['shop_id', 'reconciliation_run_id']);
            $table->index(['shop_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acknowledged_vault_discrepancies');
    }
};

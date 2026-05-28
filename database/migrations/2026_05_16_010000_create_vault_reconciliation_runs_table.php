<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vault_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->timestamp('run_at')->useCurrent();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('clean'); // clean | discrepancy_found | corrected
            $table->jsonb('discrepancy_lots')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_reconciliation_runs');
    }
};

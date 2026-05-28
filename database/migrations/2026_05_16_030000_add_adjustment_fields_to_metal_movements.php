<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('metal_movements', function (Blueprint $table) {
            $table->text('adjustment_reason')->nullable()->after('type');
            $table->foreignId('reconciliation_run_id')
                ->nullable()
                ->after('adjustment_reason')
                ->constrained('vault_reconciliation_runs')
                ->nullOnDelete();
            $table->foreignId('adjustment_approved_by')
                ->nullable()
                ->after('reconciliation_run_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('metal_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciliation_run_id');
            $table->dropConstrainedForeignId('adjustment_approved_by');
            $table->dropColumn('adjustment_reason');
        });
    }
};

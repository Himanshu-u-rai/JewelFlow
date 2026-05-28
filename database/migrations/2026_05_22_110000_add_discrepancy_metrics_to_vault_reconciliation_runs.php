<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vault_reconciliation_runs', function (Blueprint $table) {
            $table->unsignedInteger('discrepancies_found')->default(0)->after('status');
            $table->decimal('largest_delta_g', 10, 3)->nullable()->after('discrepancies_found');
        });
    }

    public function down(): void
    {
        Schema::table('vault_reconciliation_runs', function (Blueprint $table) {
            $table->dropColumn(['discrepancies_found', 'largest_delta_g']);
        });
    }
};

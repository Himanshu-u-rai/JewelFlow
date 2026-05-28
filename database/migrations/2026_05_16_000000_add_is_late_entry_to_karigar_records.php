<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('karigar_invoices', function (Blueprint $table) {
            $table->boolean('is_late_entry')->default(false)->after('discrepancy_flags');
        });

        Schema::table('karigar_payments', function (Blueprint $table) {
            $table->boolean('is_late_entry')->default(false)->after('paid_on');
        });
    }

    public function down(): void
    {
        Schema::table('karigar_invoices', function (Blueprint $table) {
            $table->dropColumn('is_late_entry');
        });

        Schema::table('karigar_payments', function (Blueprint $table) {
            $table->dropColumn('is_late_entry');
        });
    }
};

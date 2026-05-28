<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_line_items', function (Blueprint $table) {
            // Audit trail of every deduction applied at settle-time.
            // Null for returns processed before Phase A shipped (historical rows).
            // The show/receipt views treat null gracefully ("legacy return").
            $table->jsonb('policy_breakdown')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('return_line_items', function (Blueprint $table) {
            $table->dropColumn('policy_breakdown');
        });
    }
};

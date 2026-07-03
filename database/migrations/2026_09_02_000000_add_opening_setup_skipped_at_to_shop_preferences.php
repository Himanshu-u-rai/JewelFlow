<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            // Set when an owner explicitly chooses "Start Clean" (no opening
            // balances). Mirrors the return_policy_configured_at flag pattern:
            // null = not yet decided. Suppresses the onboarding prompt.
            $table->timestamp('opening_setup_skipped_at')->nullable()->after('return_policy_configured_at');
        });
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn('opening_setup_skipped_at');
        });
    }
};

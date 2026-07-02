<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding Phase 0 — defensive opening-balance marker.
 *
 * Opening rows are already segregated from live trading by date (they are
 * dated to the batch's as-of date, so `created_at < start_date` classifies
 * them as "opening" everywhere). This flag is a belt-and-suspenders second
 * axis so a report can also exclude opening by flag — protecting against an
 * operator entering a bad date, or a period that spans the as-of date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_transactions', 'is_opening')) {
                $table->boolean('is_opening')->default(false)->index();
            }
        });

        Schema::table('metal_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('metal_movements', 'is_opening')) {
                $table->boolean('is_opening')->default(false)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropColumn('is_opening');
        });

        Schema::table('metal_movements', function (Blueprint $table) {
            $table->dropColumn('is_opening');
        });
    }
};

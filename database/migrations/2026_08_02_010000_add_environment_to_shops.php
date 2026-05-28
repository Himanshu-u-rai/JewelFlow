<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shop environment classification (operational-clarity metadata only).
 *
 * Adds shops.environment so demo/seed shops can be labelled explicitly. This is
 * READ for badges/annotations ONLY — it never branches accounting, reconciliation,
 * triggers, immutability, or audit logging. Default 'production' (fail-safe toward
 * scrutiny). Set by platform admins, never by shop owners.
 *
 * @see docs/runbooks/shop-environment-classification-plan.md
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'environment')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->string('environment', 20)->default('production')->after('shop_code');
            });
        }

        // Additive CHECK constraint (not a trigger). Restricts to the three
        // approved classes; 'pilot' is intentionally excluded (pilot shops hold
        // real accounting data and must never be treated as non-production).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE shops
                ADD CONSTRAINT shops_environment_check
                CHECK (environment IN ('production','demo','internal_test'))
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shops DROP CONSTRAINT IF EXISTS shops_environment_check');
        }

        if (Schema::hasColumn('shops', 'environment')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dropColumn('environment');
            });
        }
    }
};

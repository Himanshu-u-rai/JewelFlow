<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-facing product separation (Dhiran vs Retail ERP) — Phase 0 foundation.
 *
 * Adds users.realm: which product an account belongs to. One account = one
 * product (no combined multi-product account). Additive + safe:
 *  - NOT NULL DEFAULT 'erp' → every existing user is backfilled to 'erp' by the
 *    column default, so all current ERP behaviour is unchanged.
 *  - CHECK constraint restricts values to 'erp' | 'dhiran'.
 *
 * No data is moved or deleted. The per-realm uniqueness swap is a separate
 * migration (…_make_users_mobile_number_unique_per_realm).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('realm', 20)->default('erp')->after('id');
        });

        // Backfill is implicit via the default, but be explicit for any row the
        // default somehow missed, then enforce NOT NULL + allowed values.
        DB::statement("UPDATE users SET realm = 'erp' WHERE realm IS NULL");
        DB::statement('ALTER TABLE users ALTER COLUMN realm SET NOT NULL');
        DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_realm_check");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_realm_check CHECK (realm IN ('erp','dhiran'))");

        // Helps realm-scoped lookups (login by mobile within a realm).
        DB::statement('CREATE INDEX IF NOT EXISTS users_realm_index ON users (realm)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_realm_index');
        DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_realm_check");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('realm');
        });
    }
};

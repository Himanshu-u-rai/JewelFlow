<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-realm uniqueness for user login identity (Phase 0 foundation).
 *
 * Before: users_mobile_number_unique is a UNIQUE CONSTRAINT on (mobile_number) —
 *         globally unique. (Laravel's ->unique() created it as a constraint that
 *         owns the backing index, so it must be dropped with DROP CONSTRAINT, not
 *         DROP INDEX — verified on the DB.)
 * After:  UNIQUE on (mobile_number, realm) — the SAME phone may exist once in the
 *         'erp' realm and once in 'dhiran', but never twice within one realm.
 *
 * Audit note (verified): `email` on users has NO unique index/constraint, so
 * email needs NO per-realm change — there is nothing to swap.
 *
 * Safe swap: create the new composite unique index first, verify it exists, then
 * drop the old global-unique constraint. No rows are moved or deleted. Every
 * existing row is realm='erp', so the composite still enforces the old global
 * guarantee within the erp realm.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Create the new composite unique index first.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_mobile_number_realm_unique ON users (mobile_number, realm)');

        // 2) Only remove the old global-unique guarantee once the new one is live.
        $newExists = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE tablename = 'users' AND indexname = 'users_mobile_number_realm_unique'"
        );
        if (! $newExists) {
            return; // never drop the old guard if the new one failed to create
        }

        // The old guard is a UNIQUE CONSTRAINT (owns its index) → drop the
        // constraint. Fall back to DROP INDEX for environments where it exists
        // only as a plain unique index.
        $isConstraint = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'users_mobile_number_unique' AND conrelid = 'users'::regclass"
        );
        if ($isConstraint) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_mobile_number_unique');
        } else {
            DB::statement('DROP INDEX IF EXISTS users_mobile_number_unique');
        }
    }

    public function down(): void
    {
        // Restore the global single-column unique constraint, then drop the
        // composite. (Reversible only while no two erp/dhiran rows share a
        // mobile_number; in Phase 0 all rows are erp, so this is safe.)
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'users_mobile_number_unique' AND conrelid = 'users'::regclass"
        );
        if (! $exists) {
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_mobile_number_unique UNIQUE (mobile_number)');
        }
        DB::statement('DROP INDEX IF EXISTS users_mobile_number_realm_unique');
    }
};

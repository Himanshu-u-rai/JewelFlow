<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Realm-aware password reset (Dhiran separation — Phase 1 auth hardening).
 *
 * The password_reset_tokens table is keyed by email only (PK = email), and the
 * broker resolves the user by email. Once Dhiran exists, the SAME email can have
 * an 'erp' account and a 'dhiran' account, so an email-only token is ambiguous
 * (one row per email; a reset in one realm could overwrite/match the other).
 *
 * Fix: add `realm` and re-key the table on (email, realm) so each realm holds its
 * own pending token for the same email. Additive + safe:
 *  - existing rows backfill to realm='erp' (the only realm pre-Dhiran),
 *  - swap the PK email → (email, realm) so the same email can coexist per realm.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('password_reset_tokens', 'realm')) {
            DB::statement("ALTER TABLE password_reset_tokens ADD COLUMN realm varchar(20) NOT NULL DEFAULT 'erp'");
        }
        DB::statement("UPDATE password_reset_tokens SET realm = 'erp' WHERE realm IS NULL");
        DB::statement("ALTER TABLE password_reset_tokens DROP CONSTRAINT IF EXISTS password_reset_tokens_realm_check");
        DB::statement("ALTER TABLE password_reset_tokens ADD CONSTRAINT password_reset_tokens_realm_check CHECK (realm IN ('erp','dhiran'))");

        // Re-key: drop the email-only PK, add composite (email, realm).
        DB::statement('ALTER TABLE password_reset_tokens DROP CONSTRAINT IF EXISTS password_reset_tokens_pkey');
        DB::statement('ALTER TABLE password_reset_tokens ADD PRIMARY KEY (email, realm)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE password_reset_tokens DROP CONSTRAINT IF EXISTS password_reset_tokens_pkey');
        // Restoring an email-only PK is only safe if no two realms share an email
        // (true pre-Dhiran). De-dupe defensively, then restore.
        DB::statement("DELETE FROM password_reset_tokens a USING password_reset_tokens b WHERE a.ctid < b.ctid AND a.email = b.email");
        DB::statement('ALTER TABLE password_reset_tokens ADD PRIMARY KEY (email)');
        DB::statement("ALTER TABLE password_reset_tokens DROP CONSTRAINT IF EXISTS password_reset_tokens_realm_check");
        DB::statement('ALTER TABLE password_reset_tokens DROP COLUMN IF EXISTS realm');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit_logs_append_only_guard trigger blocks ALL UPDATEs and DELETEs,
 * including the ON DELETE SET NULL cascade that fires when a user is deleted.
 * This caused staff deletion to fail with "audit_logs is append-only".
 *
 * Fix: carve out one narrow exception — allow an UPDATE where user_id goes
 * from a non-null value to NULL and no other column changes. That is the
 * exact signature of a FK cascade from user deletion, and is referential
 * integrity maintenance, not tampering. All other UPDATEs and all DELETEs
 * continue to raise the exception.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only_guard()
            RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                -- Allow FK cascade: user_id being nulled when a user row is deleted.
                -- Every other column must be unchanged for this exception to apply.
                IF TG_OP = 'UPDATE'
                    AND OLD.user_id IS NOT NULL
                    AND NEW.user_id IS NULL
                    AND OLD.shop_id             IS NOT DISTINCT FROM NEW.shop_id
                    AND OLD.action              IS NOT DISTINCT FROM NEW.action
                    AND OLD.model_type          IS NOT DISTINCT FROM NEW.model_type
                    AND OLD.model_id            IS NOT DISTINCT FROM NEW.model_id
                    AND OLD.description         IS NOT DISTINCT FROM NEW.description
                    AND OLD.created_at          IS NOT DISTINCT FROM NEW.created_at
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only_guard()
            RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$;
        SQL);
    }
};

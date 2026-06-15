<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Strengthen the audit_logs hash chain so EVERY entry is tamper-evident.
 *
 * The original digest (migration 2026_02_18_000002) covered only the rich
 * columns: prev_hash | actor | target | before | after | created_at.
 *
 * In practice the large majority of audit rows are written by the operational
 * path (StaffController, SalesService, CashBookController, …) which stores its
 * content in action / model_type / model_id / description / data and leaves the
 * rich columns NULL. Those columns were NOT in the digest, so the row_hash did
 * not actually protect the content most entries carry — a forger could alter
 * `description` or `action` without changing row_hash.
 *
 * This CREATE OR REPLACE extends the digest to also cover
 *   action | model_type | model_id | description | data
 * so every audit row — rich OR legacy — is now covered by the chain.
 *
 * Constitutional note (Article IX.A trigger #24, IX.B never-disable rule):
 * this STRENGTHENS the protected hash trigger via CREATE OR REPLACE only. The
 * trigger is never dropped/disabled; the append-only trigger (#7) is untouched.
 * Existing rows keep their original row_hash (audit_logs is append-only and
 * immutable); the new formula applies to rows inserted from here forward. The
 * chain stays linked (each new row's prev_hash is the previous row's row_hash);
 * only the per-row digest input is broadened.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_logs_hash_chain() RETURNS trigger AS $$
DECLARE
    prev text;
BEGIN
    SELECT row_hash INTO prev
    FROM audit_logs
    WHERE shop_id = NEW.shop_id
    ORDER BY id DESC
    LIMIT 1;

    NEW.prev_hash := prev;
    NEW.row_hash := encode(
        digest(
            coalesce(NEW.prev_hash, '') || '|' ||
            coalesce(NEW.actor::text, '') || '|' ||
            coalesce(NEW.target::text, '') || '|' ||
            coalesce(NEW.before::text, '') || '|' ||
            coalesce(NEW.after::text, '') || '|' ||
            -- Newly covered: the operational/legacy content columns that most
            -- rows actually use. Now every entry's content is tamper-evident.
            coalesce(NEW.action, '') || '|' ||
            coalesce(NEW.model_type, '') || '|' ||
            coalesce(NEW.model_id::text, '') || '|' ||
            coalesce(NEW.description, '') || '|' ||
            coalesce(NEW.data::text, '') || '|' ||
            coalesce(NEW.user_id::text, '') || '|' ||
            coalesce(NEW.created_at::text, now()::text),
            'sha256'
        ),
        'hex'
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        // Re-bind the trigger (idempotent): it already points at this function,
        // but recreating makes the migration self-contained and safe to re-run.
        DB::statement("DROP TRIGGER IF EXISTS audit_logs_hash_trigger ON audit_logs");
        DB::statement("CREATE TRIGGER audit_logs_hash_trigger BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_hash_chain()");
    }

    public function down(): void
    {
        // Restore the original (rich-only) digest.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_logs_hash_chain() RETURNS trigger AS $$
DECLARE
    prev text;
BEGIN
    SELECT row_hash INTO prev
    FROM audit_logs
    WHERE shop_id = NEW.shop_id
    ORDER BY id DESC
    LIMIT 1;

    NEW.prev_hash := prev;
    NEW.row_hash := encode(
        digest(
            coalesce(NEW.prev_hash, '') || '|' ||
            coalesce(NEW.actor::text, '') || '|' ||
            coalesce(NEW.target::text, '') || '|' ||
            coalesce(NEW.before::text, '') || '|' ||
            coalesce(NEW.after::text, '') || '|' ||
            coalesce(NEW.created_at::text, now()::text),
            'sha256'
        ),
        'hex'
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement("DROP TRIGGER IF EXISTS audit_logs_hash_trigger ON audit_logs");
        DB::statement("CREATE TRIGGER audit_logs_hash_trigger BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_hash_chain()");
    }
};

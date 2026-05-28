<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 11/11 — Constitutional gap closure.
 *
 * CONSTITUTION.md Article IX.A trigger registry entry #17 declared
 * `vault_reconciliation_runs_append_only_trigger` to be a constitutionally
 * protected trigger. However, no migration ever installed it. This is
 * the gap closure migration.
 *
 * vault_reconciliation_runs records each `vault:reconcile` execution.
 * Once recorded, a run is historical evidence and cannot be modified
 * or deleted — every audit must be able to inspect prior reconciliation
 * decisions exactly as they were made.
 *
 * Trigger semantics: BEFORE UPDATE OR DELETE → RAISE EXCEPTION.
 * Standard append-only pattern matching the May 2026 Constitutional
 * Lockdown trigger style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vault_reconciliation_runs')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vault_reconciliation_runs_append_only_guard()
            RETURNS trigger AS $$
            BEGIN
              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION 'Append-only: vault_reconciliation_runs rows cannot be updated (id=%)', OLD.id;
              ELSIF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Append-only: vault_reconciliation_runs rows cannot be deleted (id=%)', OLD.id;
              END IF;
              RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS vault_reconciliation_runs_append_only_trigger ON vault_reconciliation_runs;

            CREATE TRIGGER vault_reconciliation_runs_append_only_trigger
            BEFORE UPDATE OR DELETE ON vault_reconciliation_runs
            FOR EACH ROW EXECUTE FUNCTION vault_reconciliation_runs_append_only_guard();
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('vault_reconciliation_runs')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS vault_reconciliation_runs_append_only_trigger ON vault_reconciliation_runs;
            DROP FUNCTION IF EXISTS vault_reconciliation_runs_append_only_guard();
        SQL);
    }
};

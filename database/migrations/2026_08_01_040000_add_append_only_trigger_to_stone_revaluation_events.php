<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B — Migration 4/4 — Append-only trigger on revaluation ledger.
 *
 * Constitutional trigger #23 (added to CONSTITUTION.md Article IX.A
 * registry by the accompanying constitution amendment).
 *
 * stone_revaluation_events is the audit trail for every operator-
 * initiated stone revaluation. Once written, a row is forensic
 * evidence — UPDATE and DELETE are both forbidden.
 *
 * Identical structure to other append-only triggers in the registry
 * (invoice_payments, loyalty_transactions, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stone_revaluation_events')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stone_revaluation_events_append_only_guard()
            RETURNS trigger AS $$
            BEGIN
              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION 'Append-only: stone_revaluation_events rows cannot be updated (id=%)', OLD.id;
              ELSIF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Append-only: stone_revaluation_events rows cannot be deleted (id=%)', OLD.id;
              END IF;
              RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS stone_revaluation_events_append_only_trigger ON stone_revaluation_events;

            CREATE TRIGGER stone_revaluation_events_append_only_trigger
            BEFORE UPDATE OR DELETE ON stone_revaluation_events
            FOR EACH ROW EXECUTE FUNCTION stone_revaluation_events_append_only_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stone_revaluation_events_append_only_trigger ON stone_revaluation_events;
            DROP FUNCTION IF EXISTS stone_revaluation_events_append_only_guard();
        SQL);
    }
};

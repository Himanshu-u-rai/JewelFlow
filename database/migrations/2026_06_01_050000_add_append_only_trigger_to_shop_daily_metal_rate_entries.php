<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Migration 5/N — Append-only trigger on rate entries.
 *
 * `shop_daily_metal_rate_entries` is the authoritative rate ledger.
 * Once a rate is recorded for a (shop, business_date, metal_type),
 * it is immutable — any rate change creates a new row with a fresh
 * `entered_at` timestamp. This preserves the per-write history that
 * the mutable `shop_daily_metal_rates.gold_24k_rate_per_gram` column
 * destroys via in-place overwrite.
 *
 * The UNIQUE constraint on (shop_id, business_date, metal_type)
 * means a same-day re-save must INSERT … ON CONFLICT … DO UPDATE
 * which IS UPDATE-shaped. To accommodate same-day rate corrections
 * WITHOUT silently overwriting history, the service layer uses the
 * pattern: detect existing row, INSERT a "history" snapshot to the
 * dedicated metal_rates ledger, then UPDATE this current-day entry.
 *
 * Wait — that would require this table to allow UPDATE. Conflict.
 *
 * Resolution: this table holds the CURRENT-day entry only. The
 * existing `metal_rates` table (already append-only via Feb 2026
 * triggers) holds the per-write history. Same-day overrides are
 * recorded as NEW metal_rates rows; the current-day entry in THIS
 * table gets UPDATED in place to reflect the latest authoritative
 * value. To allow that, the trigger here permits UPDATE only when
 * the only changed columns are rate_per_gram, entered_at,
 * entered_by_user_id, source, updated_at — and DELETE is always
 * blocked.
 *
 * Final invariant set:
 *   - DELETE: always blocked
 *   - UPDATE: blocked unless all dirty columns are in the allow-list
 *
 * Allow-list: rate_per_gram, source, entered_by_user_id, entered_at,
 *             updated_at
 *
 * Constitutional registration: this trigger becomes #21 in
 * CONSTITUTION.md Article IX.A (registry update in the constitutional
 * article amendment migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_daily_metal_rate_entries')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION shop_daily_metal_rate_entries_guard()
            RETURNS trigger AS $$
            DECLARE
                blocked_change boolean := false;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Append-only: shop_daily_metal_rate_entries rows cannot be deleted (id=%)', OLD.id;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    -- Allow-list of columns mutable post-insert.
                    -- Anything else changing is a violation.
                    IF NEW.id            IS DISTINCT FROM OLD.id            THEN blocked_change := true; END IF;
                    IF NEW.shop_id       IS DISTINCT FROM OLD.shop_id       THEN blocked_change := true; END IF;
                    IF NEW.business_date IS DISTINCT FROM OLD.business_date THEN blocked_change := true; END IF;
                    IF NEW.metal_type    IS DISTINCT FROM OLD.metal_type    THEN blocked_change := true; END IF;
                    IF NEW.created_at    IS DISTINCT FROM OLD.created_at    THEN blocked_change := true; END IF;
                    -- rate_per_gram, source, entered_by_user_id, entered_at,
                    -- updated_at: ALLOWED to change.

                    IF blocked_change THEN
                        RAISE EXCEPTION 'Append-only invariant: cannot change identity columns on shop_daily_metal_rate_entries (id=%)', OLD.id;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS shop_daily_metal_rate_entries_guard_trigger ON shop_daily_metal_rate_entries;

            CREATE TRIGGER shop_daily_metal_rate_entries_guard_trigger
            BEFORE UPDATE OR DELETE ON shop_daily_metal_rate_entries
            FOR EACH ROW EXECUTE FUNCTION shop_daily_metal_rate_entries_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS shop_daily_metal_rate_entries_guard_trigger ON shop_daily_metal_rate_entries;
            DROP FUNCTION IF EXISTS shop_daily_metal_rate_entries_guard();
        SQL);
    }
};

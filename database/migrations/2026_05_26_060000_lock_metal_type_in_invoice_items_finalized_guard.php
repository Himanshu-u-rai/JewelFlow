<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 6/9 — Silent Wrongness Elimination
 *
 * Extends `invoice_items_finalized_guard` to also lock `metal_type` and
 * `hsn_code` on finalized/cancelled invoices. These are snapshot columns —
 * once an invoice is finalized, the metal context and tax classification
 * of every line must be immutable.
 *
 * Constitutional touchpoint: this is the SAME function maintained in
 * 2026_05_17_120000_backfill_invoice_items_allocations.php — every
 * downstream migration that touches this function CREATES OR REPLACES
 * the entire function body. Always reproduce the full allow-list.
 *
 * Locked-list as of this migration:
 *   - invoice_id, item_id, weight, rate, making_charges, stone_amount,
 *     line_total, gst_rate, gst_amount, metal_type (NEW), hsn_code (NEW)
 *
 * Allow-list (mutable post-finalize):
 *   - allocated_discount, allocated_round_off, allocated_loyalty_pts
 *   - returned_at, return_line_item_id
 *   - updated_at
 *
 * Rollback: restores prior locked-list (pre-Phase-0).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Pre-validation: confirm trigger exists. If it doesn't, we are
        // applying Phase 0 to a DB that hasn't run the earlier migrations,
        // which is a serious state mismatch — abort.
        $triggerExists = DB::selectOne(
            "SELECT 1 FROM pg_proc WHERE proname = 'invoice_items_finalized_guard'"
        );
        if (! $triggerExists) {
            throw new RuntimeException(
                'invoice_items_finalized_guard function missing. Prior allocation-backfill migration (2026_05_17_120000) has not been run.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoice_items_finalized_guard() RETURNS trigger AS $$
DECLARE
    inv_status text;
    blocked_change boolean := false;
BEGIN
    SELECT status INTO inv_status FROM invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
    IF inv_status NOT IN ('finalized', 'cancelled') THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    -- For finalized/cancelled invoices, no INSERT or DELETE permitted.
    IF TG_OP = 'INSERT' OR TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    -- UPDATE: every column NOT in the post-finalization allow-list must remain unchanged.
    IF NEW.invoice_id            IS DISTINCT FROM OLD.invoice_id            THEN blocked_change := true; END IF;
    IF NEW.item_id               IS DISTINCT FROM OLD.item_id               THEN blocked_change := true; END IF;
    IF NEW.weight                IS DISTINCT FROM OLD.weight                THEN blocked_change := true; END IF;
    IF NEW.rate                  IS DISTINCT FROM OLD.rate                  THEN blocked_change := true; END IF;
    IF NEW.making_charges        IS DISTINCT FROM OLD.making_charges        THEN blocked_change := true; END IF;
    IF NEW.stone_amount          IS DISTINCT FROM OLD.stone_amount          THEN blocked_change := true; END IF;
    IF NEW.line_total            IS DISTINCT FROM OLD.line_total            THEN blocked_change := true; END IF;
    IF NEW.gst_rate              IS DISTINCT FROM OLD.gst_rate              THEN blocked_change := true; END IF;
    IF NEW.gst_amount            IS DISTINCT FROM OLD.gst_amount            THEN blocked_change := true; END IF;
    IF NEW.metal_type            IS DISTINCT FROM OLD.metal_type            THEN blocked_change := true; END IF;
    IF NEW.hsn_code              IS DISTINCT FROM OLD.hsn_code              THEN blocked_change := true; END IF;
    -- allocated_discount, allocated_round_off, allocated_loyalty_pts,
    -- returned_at, return_line_item_id, updated_at: ALLOWED to change.

    IF blocked_change THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Restore the pre-Phase-0 locked-list (no metal_type, no hsn_code).
        // Matches the function from 2026_05_17_120000_backfill_invoice_items_allocations.php.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoice_items_finalized_guard() RETURNS trigger AS $$
DECLARE
    inv_status text;
    blocked_change boolean := false;
BEGIN
    SELECT status INTO inv_status FROM invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
    IF inv_status NOT IN ('finalized', 'cancelled') THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    IF TG_OP = 'INSERT' OR TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    IF NEW.invoice_id            IS DISTINCT FROM OLD.invoice_id            THEN blocked_change := true; END IF;
    IF NEW.item_id               IS DISTINCT FROM OLD.item_id               THEN blocked_change := true; END IF;
    IF NEW.weight                IS DISTINCT FROM OLD.weight                THEN blocked_change := true; END IF;
    IF NEW.rate                  IS DISTINCT FROM OLD.rate                  THEN blocked_change := true; END IF;
    IF NEW.making_charges        IS DISTINCT FROM OLD.making_charges        THEN blocked_change := true; END IF;
    IF NEW.stone_amount          IS DISTINCT FROM OLD.stone_amount          THEN blocked_change := true; END IF;
    IF NEW.line_total            IS DISTINCT FROM OLD.line_total            THEN blocked_change := true; END IF;
    IF NEW.gst_rate              IS DISTINCT FROM OLD.gst_rate              THEN blocked_change := true; END IF;
    IF NEW.gst_amount            IS DISTINCT FROM OLD.gst_amount            THEN blocked_change := true; END IF;

    IF blocked_change THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B — Migration 2/4 — Lock new metadata columns on snapshot.
 *
 * The existing stone_components_snapshot_guard locks identity + value
 * columns when a stone is snapshotted to a finalized invoice_item or
 * settled return. Phase 2B adds certificate/grade/supplier/photo columns
 * that must follow the same snapshot doctrine — once sold, the
 * customer's certificate is part of the immutable record.
 *
 * Updated allow-list (mutable post-snapshot):
 *   - notes
 *   - updated_at
 *
 * Updated locked-list (blocked post-snapshot):
 *   - shop_id, item_id, invoice_item_id, return_line_item_id
 *   - stone_type, carat_weight, count, unit_value, total_value
 *   - migrated_from_legacy
 *   - certificate_id, certificate_authority, grade, supplier_name, photo_path
 *
 * Pre-snapshot (item still in stock, no invoice/return link): all
 * columns mutable.
 *
 * This is a CREATE OR REPLACE FUNCTION update. The trigger itself
 * (stone_components_snapshot_guard_trigger) does NOT change; only the
 * function body it calls is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Pre-validation: confirm function exists. Refuse to update a
        // function that's missing — that would indicate the Phase 2A
        // foundation never ran.
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_proc WHERE proname = 'stone_components_snapshot_guard'"
        );
        if (! $exists) {
            throw new RuntimeException(
                'stone_components_snapshot_guard function missing. '
                . 'Phase 2A migration 2026_07_01_040000 must run before Phase 2B.'
            );
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stone_components_snapshot_guard()
            RETURNS trigger AS $$
            DECLARE
                inv_status   text;
                ret_status   text;
                is_snapshot  boolean := false;
                blocked      boolean := false;
            BEGIN
                IF COALESCE(NEW.invoice_item_id, OLD.invoice_item_id) IS NOT NULL THEN
                    SELECT i.status INTO inv_status
                    FROM invoices i
                    JOIN invoice_items ii ON ii.invoice_id = i.id
                    WHERE ii.id = COALESCE(NEW.invoice_item_id, OLD.invoice_item_id)
                    LIMIT 1;
                    IF inv_status IN ('finalized', 'cancelled') THEN
                        is_snapshot := true;
                    END IF;
                END IF;

                IF COALESCE(NEW.return_line_item_id, OLD.return_line_item_id) IS NOT NULL THEN
                    SELECT ro.status INTO ret_status
                    FROM return_orders ro
                    JOIN return_line_items rli ON rli.return_order_id = ro.id
                    WHERE rli.id = COALESCE(NEW.return_line_item_id, OLD.return_line_item_id)
                    LIMIT 1;
                    IF ret_status IN ('settled', 'cancelled') THEN
                        is_snapshot := true;
                    END IF;
                END IF;

                IF NOT is_snapshot THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Frozen: stone_components linked to finalized invoice or settled return cannot be deleted (id=%)', OLD.id;
                END IF;

                -- Locked columns on snapshot. Adding Phase 2B fields here.
                IF NEW.shop_id               IS DISTINCT FROM OLD.shop_id               THEN blocked := true; END IF;
                IF NEW.item_id               IS DISTINCT FROM OLD.item_id               THEN blocked := true; END IF;
                IF NEW.invoice_item_id       IS DISTINCT FROM OLD.invoice_item_id       THEN blocked := true; END IF;
                IF NEW.return_line_item_id   IS DISTINCT FROM OLD.return_line_item_id   THEN blocked := true; END IF;
                IF NEW.stone_type            IS DISTINCT FROM OLD.stone_type            THEN blocked := true; END IF;
                IF NEW.carat_weight          IS DISTINCT FROM OLD.carat_weight          THEN blocked := true; END IF;
                IF NEW.count                 IS DISTINCT FROM OLD.count                 THEN blocked := true; END IF;
                IF NEW.unit_value            IS DISTINCT FROM OLD.unit_value            THEN blocked := true; END IF;
                IF NEW.total_value           IS DISTINCT FROM OLD.total_value           THEN blocked := true; END IF;
                IF NEW.migrated_from_legacy  IS DISTINCT FROM OLD.migrated_from_legacy  THEN blocked := true; END IF;
                -- Phase 2B additions to locked-list:
                IF NEW.certificate_id        IS DISTINCT FROM OLD.certificate_id        THEN blocked := true; END IF;
                IF NEW.certificate_authority IS DISTINCT FROM OLD.certificate_authority THEN blocked := true; END IF;
                IF NEW.grade                 IS DISTINCT FROM OLD.grade                 THEN blocked := true; END IF;
                IF NEW.supplier_name         IS DISTINCT FROM OLD.supplier_name         THEN blocked := true; END IF;
                IF NEW.photo_path            IS DISTINCT FROM OLD.photo_path            THEN blocked := true; END IF;
                -- notes, updated_at: ALLOWED to change.

                IF blocked THEN
                    RAISE EXCEPTION 'Frozen: stone_components snapshot fields are immutable; only `notes` may be edited (id=%)', OLD.id;
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

        // Restore Phase 2A function body (no Phase 2B columns in the locked-list).
        // If Phase 2B columns have been added to stone_components but this
        // function reverts, the new columns would be silently mutable
        // post-snapshot — that's why down() should ONLY run when paired with
        // migration 010000.down() (which drops the columns).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stone_components_snapshot_guard()
            RETURNS trigger AS $$
            DECLARE
                inv_status   text;
                ret_status   text;
                is_snapshot  boolean := false;
                blocked      boolean := false;
            BEGIN
                IF COALESCE(NEW.invoice_item_id, OLD.invoice_item_id) IS NOT NULL THEN
                    SELECT i.status INTO inv_status
                    FROM invoices i
                    JOIN invoice_items ii ON ii.invoice_id = i.id
                    WHERE ii.id = COALESCE(NEW.invoice_item_id, OLD.invoice_item_id)
                    LIMIT 1;
                    IF inv_status IN ('finalized', 'cancelled') THEN
                        is_snapshot := true;
                    END IF;
                END IF;

                IF COALESCE(NEW.return_line_item_id, OLD.return_line_item_id) IS NOT NULL THEN
                    SELECT ro.status INTO ret_status
                    FROM return_orders ro
                    JOIN return_line_items rli ON rli.return_order_id = ro.id
                    WHERE rli.id = COALESCE(NEW.return_line_item_id, OLD.return_line_item_id)
                    LIMIT 1;
                    IF ret_status IN ('settled', 'cancelled') THEN
                        is_snapshot := true;
                    END IF;
                END IF;

                IF NOT is_snapshot THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Frozen: stone_components linked to finalized invoice or settled return cannot be deleted (id=%)', OLD.id;
                END IF;

                IF NEW.shop_id              IS DISTINCT FROM OLD.shop_id              THEN blocked := true; END IF;
                IF NEW.item_id              IS DISTINCT FROM OLD.item_id              THEN blocked := true; END IF;
                IF NEW.invoice_item_id      IS DISTINCT FROM OLD.invoice_item_id      THEN blocked := true; END IF;
                IF NEW.return_line_item_id  IS DISTINCT FROM OLD.return_line_item_id  THEN blocked := true; END IF;
                IF NEW.stone_type           IS DISTINCT FROM OLD.stone_type           THEN blocked := true; END IF;
                IF NEW.carat_weight         IS DISTINCT FROM OLD.carat_weight         THEN blocked := true; END IF;
                IF NEW.count                IS DISTINCT FROM OLD.count                THEN blocked := true; END IF;
                IF NEW.unit_value           IS DISTINCT FROM OLD.unit_value           THEN blocked := true; END IF;
                IF NEW.total_value          IS DISTINCT FROM OLD.total_value          THEN blocked := true; END IF;
                IF NEW.migrated_from_legacy IS DISTINCT FROM OLD.migrated_from_legacy THEN blocked := true; END IF;

                IF blocked THEN
                    RAISE EXCEPTION 'Frozen: stone_components snapshot fields are immutable; only `notes` may be edited (id=%)', OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};

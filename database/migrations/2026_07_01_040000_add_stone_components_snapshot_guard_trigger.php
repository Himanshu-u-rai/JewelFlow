<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A — Migration 4/N — Stone components snapshot guard.
 *
 * Once a stone_components row is linked to a finalized invoice_item or
 * a settled return_line_item, the row becomes constitutionally immutable.
 * The only mutable column post-snapshot is `notes` (operator can attach
 * descriptive narrative without touching financial fields).
 *
 * Snapshot semantics:
 *   - Row tied to an inventory `item_id` only (no invoice/return) →
 *     fully mutable. Operator can edit the stone while item is in stock.
 *   - Row tied to an `invoice_item_id` whose parent invoice is
 *     'finalized' or 'cancelled' → frozen. UPDATE blocked (except notes),
 *     DELETE blocked.
 *   - Row tied to a `return_line_item_id` whose parent return is
 *     'settled' or 'cancelled' → frozen. UPDATE blocked (except notes),
 *     DELETE blocked.
 *
 * DELETE is blocked unconditionally when ANY snapshot is active —
 * stones can be replaced (new row + old row marked superseded) but
 * never removed from history.
 *
 * Constitutional Trigger Registry entry #22 (added to CONSTITUTION.md
 * Article IX.A by this migration's accompanying constitutional update).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stone_components')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
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
                -- Determine if either parent record indicates a snapshot.
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
                    -- Not snapshotted: mutation freely permitted.
                    RETURN COALESCE(NEW, OLD);
                END IF;

                -- Snapshotted: enforce immutability.
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Frozen: stone_components linked to finalized invoice or settled return cannot be deleted (id=%)', OLD.id;
                END IF;

                -- UPDATE: only `notes` may change.
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
                -- notes, updated_at: ALLOWED to change.

                IF blocked THEN
                    RAISE EXCEPTION 'Frozen: stone_components snapshot fields are immutable; only `notes` may be edited (id=%)', OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS stone_components_snapshot_guard_trigger ON stone_components;

            CREATE TRIGGER stone_components_snapshot_guard_trigger
            BEFORE UPDATE OR DELETE ON stone_components
            FOR EACH ROW EXECUTE FUNCTION stone_components_snapshot_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stone_components_snapshot_guard_trigger ON stone_components;
            DROP FUNCTION IF EXISTS stone_components_snapshot_guard();
        SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The invoices_accounting_guard trigger blocks UPDATEs on finalized/cancelled invoices.
        // We temporarily replace it with a version that allows backfilling the new CGST/SGST/IGST
        // columns (which don't touch financial totals), then restore the strict guard.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_accounting_guard() RETURNS trigger AS $$
DECLARE
    lock_date date;
    item_sum numeric(18,2);
    item_count bigint;
    expected_total numeric(18,2);
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.status = 'cancelled' THEN
            -- Only block changes to core financial/identity fields on cancelled invoices.
            -- New analytics columns (cgst_amount, sgst_amount, igst_amount, cgst_was_backfilled,
            -- gst_override) are allowed for backfill.
            IF NEW.status IS DISTINCT FROM OLD.status
                OR NEW.shop_id IS DISTINCT FROM OLD.shop_id
                OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                OR NEW.invoice_number IS DISTINCT FROM OLD.invoice_number
                OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                OR NEW.gst IS DISTINCT FROM OLD.gst
                OR NEW.total IS DISTINCT FROM OLD.total THEN
                RAISE EXCEPTION 'Cancelled invoice is immutable';
            END IF;
        END IF;

        IF OLD.status = 'finalized' THEN
            IF NEW.status <> 'cancelled' THEN
                -- Allow changing only the new analytics columns; block changes to financial fields.
                IF NEW.shop_id IS DISTINCT FROM OLD.shop_id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.invoice_number IS DISTINCT FROM OLD.invoice_number
                    OR NEW.invoice_sequence IS DISTINCT FROM OLD.invoice_sequence
                    OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                    OR NEW.gst IS DISTINCT FROM OLD.gst
                    OR NEW.wastage_charge IS DISTINCT FROM OLD.wastage_charge
                    OR NEW.total IS DISTINCT FROM OLD.total
                    OR NEW.reversal_of_invoice_id IS DISTINCT FROM OLD.reversal_of_invoice_id
                    OR NEW.finalized_at IS DISTINCT FROM OLD.finalized_at
                    OR NEW.finalized_by IS DISTINCT FROM OLD.finalized_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Finalized invoice fields cannot be edited';
                END IF;
            ELSE
                IF NEW.shop_id IS DISTINCT FROM OLD.shop_id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.invoice_number IS DISTINCT FROM OLD.invoice_number
                    OR NEW.invoice_sequence IS DISTINCT FROM OLD.invoice_sequence
                    OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                    OR NEW.gst IS DISTINCT FROM OLD.gst
                    OR NEW.wastage_charge IS DISTINCT FROM OLD.wastage_charge
                    OR NEW.total IS DISTINCT FROM OLD.total
                    OR NEW.reversal_of_invoice_id IS DISTINCT FROM OLD.reversal_of_invoice_id
                    OR NEW.finalized_at IS DISTINCT FROM OLD.finalized_at
                    OR NEW.finalized_by IS DISTINCT FROM OLD.finalized_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Finalized invoice fields cannot be edited';
                END IF;
            END IF;
        END IF;
    END IF;

    SELECT financial_lock_date INTO lock_date FROM shop_rules WHERE shop_id = NEW.shop_id;

    IF lock_date IS NOT NULL THEN
        IF TG_OP = 'INSERT' AND NEW.status = 'finalized' AND NEW.created_at::date <= lock_date THEN
            RAISE EXCEPTION 'Financial lock active through %, invoice date %', lock_date, NEW.created_at::date;
        END IF;
        IF TG_OP = 'UPDATE' AND OLD.status = 'finalized' AND NEW.status = 'cancelled' AND OLD.created_at::date <= lock_date THEN
            RAISE EXCEPTION 'Financial lock active through %, reversal blocked for invoice date %', lock_date, OLD.created_at::date;
        END IF;
    END IF;

    IF (TG_OP = 'INSERT' AND NEW.status = 'finalized')
        OR (TG_OP = 'UPDATE' AND OLD.status = 'draft' AND NEW.status = 'finalized') THEN

        SELECT COALESCE(SUM(line_total), 0), COUNT(*) INTO item_sum, item_count
        FROM invoice_items
        WHERE invoice_id = NEW.id;

        IF item_count > 0 AND ROUND(NEW.subtotal::numeric, 2) <> ROUND(item_sum::numeric, 2) THEN
            RAISE EXCEPTION 'Invoice subtotal mismatch: subtotal %, item_sum %', NEW.subtotal, item_sum;
        END IF;

        expected_total := ROUND(COALESCE(NEW.subtotal,0) + COALESCE(NEW.gst,0) + COALESCE(NEW.wastage_charge,0), 2);
        IF ROUND(NEW.total::numeric, 2) <> expected_total THEN
            RAISE EXCEPTION 'Invoice total mismatch: total %, expected %', NEW.total, expected_total;
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        // Run the backfill with the relaxed trigger
        DB::statement("
            UPDATE invoices
            SET
                cgst_amount = ROUND(gst / 2.0, 2),
                sgst_amount = ROUND(gst - ROUND(gst / 2.0, 2), 2),
                igst_amount = 0,
                cgst_was_backfilled = TRUE
            WHERE cgst_amount IS NULL
              AND status IN ('finalized', 'cancelled', 'reversed')
        ");

        // Restore the strict guard (matches the version from
        // 2026_02_18_000003_strengthen_invoice_db_immutability.php but without the
        // now-nonexistent 'notes' column)
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_accounting_guard() RETURNS trigger AS $$
DECLARE
    lock_date date;
    item_sum numeric(18,2);
    item_count bigint;
    expected_total numeric(18,2);
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.status = 'cancelled' THEN
            RAISE EXCEPTION 'Cancelled invoice is immutable';
        END IF;

        IF OLD.status = 'finalized' THEN
            IF NEW.status <> 'cancelled' THEN
                RAISE EXCEPTION 'Finalized invoice is immutable. Use reversal flow.';
            END IF;

            IF NEW.shop_id IS DISTINCT FROM OLD.shop_id
                OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                OR NEW.invoice_number IS DISTINCT FROM OLD.invoice_number
                OR NEW.invoice_sequence IS DISTINCT FROM OLD.invoice_sequence
                OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                OR NEW.gst IS DISTINCT FROM OLD.gst
                OR NEW.wastage_charge IS DISTINCT FROM OLD.wastage_charge
                OR NEW.total IS DISTINCT FROM OLD.total
                OR NEW.reversal_of_invoice_id IS DISTINCT FROM OLD.reversal_of_invoice_id
                OR NEW.finalized_at IS DISTINCT FROM OLD.finalized_at
                OR NEW.finalized_by IS DISTINCT FROM OLD.finalized_by
                OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'Finalized invoice fields cannot be edited';
            END IF;
        END IF;
    END IF;

    SELECT financial_lock_date INTO lock_date FROM shop_rules WHERE shop_id = NEW.shop_id;

    IF lock_date IS NOT NULL THEN
        IF TG_OP = 'INSERT' AND NEW.status = 'finalized' AND NEW.created_at::date <= lock_date THEN
            RAISE EXCEPTION 'Financial lock active through %, invoice date %', lock_date, NEW.created_at::date;
        END IF;
        IF TG_OP = 'UPDATE' AND OLD.status = 'finalized' AND NEW.status = 'cancelled' AND OLD.created_at::date <= lock_date THEN
            RAISE EXCEPTION 'Financial lock active through %, reversal blocked for invoice date %', lock_date, OLD.created_at::date;
        END IF;
    END IF;

    IF (TG_OP = 'INSERT' AND NEW.status = 'finalized')
        OR (TG_OP = 'UPDATE' AND OLD.status = 'draft' AND NEW.status = 'finalized') THEN

        SELECT COALESCE(SUM(line_total), 0), COUNT(*) INTO item_sum, item_count
        FROM invoice_items
        WHERE invoice_id = NEW.id;

        IF item_count > 0 AND ROUND(NEW.subtotal::numeric, 2) <> ROUND(item_sum::numeric, 2) THEN
            RAISE EXCEPTION 'Invoice subtotal mismatch: subtotal %, item_sum %', NEW.subtotal, item_sum;
        END IF;

        expected_total := ROUND(COALESCE(NEW.subtotal,0) + COALESCE(NEW.gst,0) + COALESCE(NEW.wastage_charge,0), 2);
        IF ROUND(NEW.total::numeric, 2) <> expected_total THEN
            RAISE EXCEPTION 'Invoice total mismatch: total %, expected %', NEW.total, expected_total;
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        DB::statement("
            UPDATE invoices
            SET cgst_amount = NULL, sgst_amount = NULL, igst_amount = NULL, cgst_was_backfilled = FALSE
        ");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restore discount + round_off to the invoices_accounting_guard total check.
 *
 * Regression history:
 *   - 2026_03_06 / 2026_04_09 correctly defined expected_total as
 *       subtotal + gst + wastage - discount + round_off
 *   - 2026_05_22_020000 (CGST/SGST backfill) re-created the guard function to
 *     relax the cancelled/finalized immutability rules for the new analytics
 *     columns, but copied a STALE body whose expected_total dropped discount
 *     and round_off:  subtotal + gst + wastage
 *
 * Effect: any finalized invoice with a non-zero discount or round-off failed
 * the guard ("Invoice total mismatch") because finalizeDraft() computes
 * total = subtotal + gst + wastage - discount + round_off. This blocked every
 * POS sale that produced a rounding adjustment (Quote V2 shops always do).
 *
 * This migration re-creates the function with the 2026_05_22 body intact
 * (immutability + financial-lock + analytics-column allowances preserved) and
 * only the expected_total formula corrected. The trigger itself is never
 * dropped or disabled (Constitution Art. IX).
 */
return new class extends Migration
{
    public function up(): void
    {
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

        expected_total := ROUND(
            COALESCE(NEW.subtotal, 0)
            + COALESCE(NEW.gst, 0)
            + COALESCE(NEW.wastage_charge, 0)
            - COALESCE(NEW.discount, 0)
            + COALESCE(NEW.round_off, 0),
            2
        );
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
        // Revert to the 2026_05_22 formula (without discount/round_off).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_accounting_guard() RETURNS trigger AS $$
DECLARE
    lock_date date;
    item_sum numeric(18,2);
    item_count bigint;
    expected_total numeric(18,2);
BEGIN
    IF (TG_OP = 'INSERT' AND NEW.status = 'finalized')
        OR (TG_OP = 'UPDATE' AND OLD.status = 'draft' AND NEW.status = 'finalized') THEN
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
};

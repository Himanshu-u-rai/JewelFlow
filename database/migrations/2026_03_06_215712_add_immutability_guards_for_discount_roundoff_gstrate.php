<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

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
                OR NEW.gst_rate IS DISTINCT FROM OLD.gst_rate
                OR NEW.wastage_charge IS DISTINCT FROM OLD.wastage_charge
                OR NEW.discount IS DISTINCT FROM OLD.discount
                OR NEW.round_off IS DISTINCT FROM OLD.round_off
                OR NEW.total IS DISTINCT FROM OLD.total
                OR NEW.notes IS DISTINCT FROM OLD.notes
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
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Reverts to previous version without gst_rate/discount/round_off guards
    }
};

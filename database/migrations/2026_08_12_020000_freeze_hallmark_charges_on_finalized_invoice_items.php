<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add hallmark_charges to the post-finalization frozen list of the
 * invoice_items_finalized_guard (Constitution Article IX.A). Consistency with
 * making_charges / stone_amount / line_total: once an invoice is finalized, its
 * hallmark breakdown is immutable too.
 *
 * This is an ADDITIVE change applied via CREATE OR REPLACE FUNCTION — the
 * trigger is NEVER dropped or disabled, so it keeps firing throughout (honours
 * the never-disable rule). The body is byte-identical to the deployed function
 * except for the one new hallmark_charges check.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.invoice_items_finalized_guard()
             RETURNS trigger
             LANGUAGE plpgsql
            AS $function$
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
                IF NEW.hallmark_charges      IS DISTINCT FROM OLD.hallmark_charges      THEN blocked_change := true; END IF;
                IF NEW.line_total            IS DISTINCT FROM OLD.line_total            THEN blocked_change := true; END IF;
                IF NEW.gst_rate              IS DISTINCT FROM OLD.gst_rate              THEN blocked_change := true; END IF;
                IF NEW.gst_amount            IS DISTINCT FROM OLD.gst_amount            THEN blocked_change := true; END IF;
                IF NEW.metal_type            IS DISTINCT FROM OLD.metal_type            THEN blocked_change := true; END IF;
                IF NEW.hsn_code              IS DISTINCT FROM OLD.hsn_code              THEN blocked_change := true; END IF;

                IF blocked_change THEN
                    RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
                END IF;

                RETURN NEW;
            END;
            $function$
        SQL);
    }

    public function down(): void
    {
        // Restore the original (without the hallmark_charges check).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.invoice_items_finalized_guard()
             RETURNS trigger
             LANGUAGE plpgsql
            AS $function$
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
                IF NEW.metal_type            IS DISTINCT FROM OLD.metal_type            THEN blocked_change := true; END IF;
                IF NEW.hsn_code              IS DISTINCT FROM OLD.hsn_code              THEN blocked_change := true; END IF;

                IF blocked_change THEN
                    RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
                END IF;

                RETURN NEW;
            END;
            $function$
        SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restoration M5 / finding A2b — repair the constitutionally-protected trigger
 * karigar_invoices_finalized_guard_trigger (Constitution Art. IX.A #15).
 *
 * The original function body guarded on OLD.status, a column that does NOT exist
 * on karigar_invoices (the table uses payment_status). Every UPDATE therefore
 * crashed with «record "old" has no field "status"», which broke normal payment
 * recording (recordPayment updates payment_status) — latent only because no
 * karigar payment had been recorded yet.
 *
 * This CREATE OR REPLACE preserves the trigger NAME and its protective intent
 * ("freeze finalized karigar invoice content"): it keeps the append-only DELETE
 * block and, once an invoice is no longer 'unpaid', freezes the billed CONTENT
 * (monetary totals + invoice identity) while still allowing the payment-lifecycle
 * fields (payment_status, amount_paid) to change so payments can be recorded and
 * reversed. The trigger is NOT deleted, disabled, or renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('karigar_invoices')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION karigar_invoices_finalized_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Append-only: karigar_invoices rows cannot be deleted (id=%)', OLD.id;
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    -- Once any payment has been recorded (status is no longer
                    -- 'unpaid'), the billed content is frozen. Payment-tracking
                    -- fields stay mutable so payments can be recorded/reversed.
                    IF OLD.payment_status IS DISTINCT FROM 'unpaid' THEN
                        IF OLD.total_after_tax        IS DISTINCT FROM NEW.total_after_tax
                        OR OLD.total_before_tax       IS DISTINCT FROM NEW.total_before_tax
                        OR OLD.total_tax              IS DISTINCT FROM NEW.total_tax
                        OR OLD.karigar_invoice_number IS DISTINCT FROM NEW.karigar_invoice_number
                        OR OLD.karigar_invoice_date   IS DISTINCT FROM NEW.karigar_invoice_date
                        OR OLD.karigar_id             IS DISTINCT FROM NEW.karigar_id THEN
                            RAISE EXCEPTION 'Frozen: finalized karigar invoice content cannot be changed (id=%)', OLD.id;
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        if (!Schema::hasTable('karigar_invoices')) {
            return;
        }

        // Restore the prior (broken) definition for a faithful inverse.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION karigar_invoices_finalized_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Append-only: karigar_invoices rows cannot be deleted (id=%)', OLD.id;
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.status IN ('finalized', 'paid') THEN
                        RAISE EXCEPTION 'Frozen: karigar_invoice cannot be updated after finalization (id=%)', OLD.id;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};

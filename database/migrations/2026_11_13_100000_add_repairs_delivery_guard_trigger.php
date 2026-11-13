<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit H1 (2026-11-13): a repair is delivered exactly when it is billed, and a billed repair is final.
 *
 * The model checks this too, but a writer holding a copy loaded before the delivery cannot see it; the row can.
 * Rows delivered before invoices were linked (no invoice_id) are left as they are: the rule only stops a row from
 * BECOMING delivered without an invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CONSTITUTION §4 Rule 2: never install the guard over rows that already break it. An open repair carrying an
        // invoice must be looked at by a person first. (Delivered rows without an invoice are legacy and allowed.)
        $violating = DB::table('repairs')->where('status', '<>', 'delivered')->whereNotNull('invoice_id')->orderBy('id')->pluck('id');
        if ($violating->isNotEmpty()) {
            throw new \RuntimeException('repairs_delivery_guard: open repairs carry an invoice; resolve them first. repair ids: '
                . $violating->implode(', '));
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION repairs_delivery_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'delivered' THEN
                        RAISE EXCEPTION 'A delivered repair is billed and cannot be deleted (id=%)', OLD.id;
                    END IF;
                    RETURN OLD;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.status = 'delivered' AND (
                    NEW.status IS DISTINCT FROM OLD.status
                    OR NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
                    OR NEW.final_cost IS DISTINCT FROM OLD.final_cost
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                ) THEN
                    RAISE EXCEPTION 'A delivered repair is billed and cannot be changed (id=%)', OLD.id;
                END IF;

                IF NEW.status <> 'delivered' AND NEW.invoice_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Only a delivered repair carries an invoice (id=%)', NEW.id;
                END IF;

                IF NEW.status = 'delivered' AND NEW.invoice_id IS NULL
                    AND (TG_OP = 'INSERT' OR OLD.status IS DISTINCT FROM 'delivered') THEN
                    RAISE EXCEPTION 'A repair cannot be delivered without an invoice (id=%)', NEW.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS repairs_delivery_guard_trigger ON repairs;

            CREATE TRIGGER repairs_delivery_guard_trigger
            BEFORE INSERT OR UPDATE OR DELETE ON repairs
            FOR EACH ROW EXECUTE FUNCTION repairs_delivery_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS repairs_delivery_guard_trigger ON repairs;
            DROP FUNCTION IF EXISTS repairs_delivery_guard();
        SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
                    IF OLD.status IN ('finalized', 'paid') THEN
                        RAISE EXCEPTION 'Frozen: karigar_invoice cannot be updated after finalization (id=%)', OLD.id;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS karigar_invoices_finalized_guard_trigger ON karigar_invoices;

            CREATE TRIGGER karigar_invoices_finalized_guard_trigger
            BEFORE UPDATE OR DELETE ON karigar_invoices
            FOR EACH ROW EXECUTE FUNCTION karigar_invoices_finalized_guard();
        SQL);
    }

    public function down(): void
    {
        if (!Schema::hasTable('karigar_invoices')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS karigar_invoices_finalized_guard_trigger ON karigar_invoices;
            DROP FUNCTION IF EXISTS karigar_invoices_finalized_guard();
        SQL);
    }
};

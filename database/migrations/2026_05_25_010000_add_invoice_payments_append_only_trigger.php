<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoice_payments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_payments_append_only_guard()
            RETURNS trigger AS $$
            BEGIN
              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION 'Append-only: invoice_payments rows cannot be updated (id=%)', OLD.id;
              ELSIF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Append-only: invoice_payments rows cannot be deleted (id=%)', OLD.id;
              END IF;
              RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS invoice_payments_append_only_trigger ON invoice_payments;

            CREATE TRIGGER invoice_payments_append_only_trigger
            BEFORE UPDATE OR DELETE ON invoice_payments
            FOR EACH ROW EXECUTE FUNCTION invoice_payments_append_only_guard();
        SQL);
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoice_payments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS invoice_payments_append_only_trigger ON invoice_payments;
            DROP FUNCTION IF EXISTS invoice_payments_append_only_guard();
        SQL);
    }
};

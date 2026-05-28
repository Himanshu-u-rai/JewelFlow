<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customer_gold_transactions')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION customer_gold_transactions_append_only_guard()
            RETURNS trigger AS $$
            BEGIN
              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION 'Append-only: customer_gold_transactions rows cannot be updated (id=%)', OLD.id;
              ELSIF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Append-only: customer_gold_transactions rows cannot be deleted (id=%)', OLD.id;
              END IF;
              RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS customer_gold_transactions_append_only_trigger ON customer_gold_transactions;

            CREATE TRIGGER customer_gold_transactions_append_only_trigger
            BEFORE UPDATE OR DELETE ON customer_gold_transactions
            FOR EACH ROW EXECUTE FUNCTION customer_gold_transactions_append_only_guard();
        SQL);
    }

    public function down(): void
    {
        if (!Schema::hasTable('customer_gold_transactions')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS customer_gold_transactions_append_only_trigger ON customer_gold_transactions;
            DROP FUNCTION IF EXISTS customer_gold_transactions_append_only_guard();
        SQL);
    }
};

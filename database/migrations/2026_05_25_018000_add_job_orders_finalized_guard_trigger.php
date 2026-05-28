<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('job_orders')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION job_orders_finalized_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Append-only: job_orders rows cannot be deleted (id=%)', OLD.id;
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.status IN ('completed', 'cancelled') THEN
                        RAISE EXCEPTION 'Frozen: job_order cannot be updated after completion or cancellation (id=%)', OLD.id;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS job_orders_finalized_guard_trigger ON job_orders;

            CREATE TRIGGER job_orders_finalized_guard_trigger
            BEFORE UPDATE OR DELETE ON job_orders
            FOR EACH ROW EXECUTE FUNCTION job_orders_finalized_guard();
        SQL);
    }

    public function down(): void
    {
        if (!Schema::hasTable('job_orders')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS job_orders_finalized_guard_trigger ON job_orders;
            DROP FUNCTION IF EXISTS job_orders_finalized_guard();
        SQL);
    }
};

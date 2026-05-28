<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('return_line_items')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION return_line_items_settled_guard()
            RETURNS trigger AS $$
            DECLARE
                order_status TEXT;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Append-only: return_line_items rows cannot be deleted (id=%)', OLD.id;
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    SELECT status INTO order_status
                    FROM return_orders
                    WHERE id = OLD.return_order_id;
                    IF order_status IN ('settled', 'cancelled') THEN
                        RAISE EXCEPTION 'Frozen: return_line_items cannot be updated after return is settled (id=%)', OLD.id;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS return_line_items_settled_guard_trigger ON return_line_items;

            CREATE TRIGGER return_line_items_settled_guard_trigger
            BEFORE UPDATE OR DELETE ON return_line_items
            FOR EACH ROW EXECUTE FUNCTION return_line_items_settled_guard();
        SQL);
    }

    public function down(): void
    {
        if (!Schema::hasTable('return_line_items')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS return_line_items_settled_guard_trigger ON return_line_items;
            DROP FUNCTION IF EXISTS return_line_items_settled_guard();
        SQL);
    }
};

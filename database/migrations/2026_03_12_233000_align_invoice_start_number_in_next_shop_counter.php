<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (!Schema::hasTable('shop_counters')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION next_shop_counter(p_shop_id bigint, p_counter_key text)
RETURNS bigint AS $$
DECLARE
    v_current bigint;
    v_next bigint;
    v_initial bigint := 0;
BEGIN
    IF p_counter_key = 'invoice' THEN
        SELECT (invoice_start_number - 1)
        INTO v_initial
        FROM shop_billing_settings
        WHERE shop_id = p_shop_id
        LIMIT 1;

        IF v_initial IS NULL OR v_initial < 0 THEN
            v_initial := 1000;
        END IF;
    END IF;

    INSERT INTO shop_counters (shop_id, counter_key, current_value, created_at, updated_at)
    VALUES (p_shop_id, p_counter_key, v_initial, now(), now())
    ON CONFLICT (shop_id, counter_key) DO NOTHING;

    SELECT current_value INTO v_current
    FROM shop_counters
    WHERE shop_id = p_shop_id AND counter_key = p_counter_key
    FOR UPDATE;

    IF v_current IS NULL THEN
        RAISE EXCEPTION 'Counter not found for shop % key %', p_shop_id, p_counter_key;
    END IF;

    v_next := v_current + 1;

    UPDATE shop_counters
    SET current_value = v_next,
        updated_at = now()
    WHERE shop_id = p_shop_id
      AND counter_key = p_counter_key;

    RETURN v_next;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (!Schema::hasTable('shop_counters')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION next_shop_counter(p_shop_id bigint, p_counter_key text)
RETURNS bigint AS $$
DECLARE
    v_current bigint;
    v_next bigint;
BEGIN
    INSERT INTO shop_counters (shop_id, counter_key, current_value, created_at, updated_at)
    VALUES (p_shop_id, p_counter_key, 0, now(), now())
    ON CONFLICT (shop_id, counter_key) DO NOTHING;

    SELECT current_value INTO v_current
    FROM shop_counters
    WHERE shop_id = p_shop_id AND counter_key = p_counter_key
    FOR UPDATE;

    IF v_current IS NULL THEN
        RAISE EXCEPTION 'Counter not found for shop % key %', p_shop_id, p_counter_key;
    END IF;

    v_next := v_current + 1;

    UPDATE shop_counters
    SET current_value = v_next,
        updated_at = now()
    WHERE shop_id = p_shop_id
      AND counter_key = p_counter_key;

    RETURN v_next;
END;
$$ LANGUAGE plpgsql;
SQL);
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Read-only companions to next_invoice_identity() (2026_11_16_100000), so Settings can show "Next invoice number"
 * and name the numbers a starting-number jump would leave unused — without consuming a number. Same series and
 * rendering rules: render_invoice_number() is now the one place a number is spelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION render_invoice_number(p_shop_id bigint, p_fy text, p_sequence bigint) RETURNS text AS $$
DECLARE s record;
BEGIN
    SELECT invoice_prefix, invoice_suffix INTO s FROM shop_billing_settings WHERE shop_id = p_shop_id LIMIT 1;
    RETURN render_invoice_affix(COALESCE(s.invoice_prefix, 'INV-'), p_fy, false)
        || p_sequence::text
        || render_invoice_affix(s.invoice_suffix, p_fy, true);
END;
$$ LANGUAGE plpgsql STABLE;

-- The identity the next bill in this year would get, and the counter it would come from. Consumes nothing.
-- Dropped first: an earlier draft of this function had different OUT columns, which CREATE OR REPLACE refuses.
DROP FUNCTION IF EXISTS peek_invoice_identity(bigint, text);
CREATE FUNCTION peek_invoice_identity(p_shop_id bigint, p_fy text,
    OUT sequence_value bigint, OUT invoice_number text, OUT series text, OUT counter_value bigint) AS $$
DECLARE
    v_seed bigint;
    v_key text;
BEGIN
    series := invoice_series_for(p_shop_id, p_fy);
    v_key := CASE WHEN series = 'yearly' THEN 'invoice:' || p_fy ELSE 'invoice' END;
    SELECT c.current_value INTO counter_value FROM shop_counters c WHERE c.shop_id = p_shop_id AND c.counter_key = v_key;
    IF counter_value IS NULL THEN
        SELECT (invoice_start_number - 1) INTO v_seed FROM shop_billing_settings WHERE shop_id = p_shop_id LIMIT 1;
        counter_value := CASE WHEN v_seed IS NULL OR v_seed < 0 THEN 1000 ELSE v_seed END;
    END IF;
    sequence_value := counter_value + 1;
    invoice_number := render_invoice_number(p_shop_id, p_fy, sequence_value);
END;
$$ LANGUAGE plpgsql STABLE;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared('DROP FUNCTION IF EXISTS peek_invoice_identity(bigint, text); DROP FUNCTION IF EXISTS render_invoice_number(bigint, text, bigint);');
    }
};

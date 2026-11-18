<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financial-year invoice numbering (owner sign-off 2026-11-16, "start solving all").
 *
 * The old year_reset switch reset the shared 'invoice' counter on 1 April, but uniqueness was (shop, sequence)
 * forever, so the first sale of the year collided with last year's number and every sale failed. Now:
 *   - every invoice and numbering event records its financial year (fiscal_year, '2026-27'); uniqueness is
 *     (shop, fiscal_year, sequence) and (shop, invoice_number) still holds;
 *   - a shop chooses a continuous series or a yearly one (counter key 'invoice:<fy>', from/until a year); a
 *     yearly series needs {FY} in the prefix or suffix, else it stays continuous so a sale is never blocked;
 *   - a draft billed in a later year than it was started moves to that year (and, when a yearly series is
 *     involved, takes that year's number; the old number is recorded as 'voided').
 *
 * Constitutional triggers #6 (invoices_numbering_event) and #29 (invoices_numbering_guard) are REPLACED with
 * CREATE OR REPLACE FUNCTION, never disabled (Article IX.B). The backfill writes only the new columns, which no
 * guard watches yet; the pre-validation aborts before the new unique keys if any year holds a duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('invoices', function (Blueprint $t) {
            $t->string('fiscal_year', 7)->nullable()->after('invoice_sequence');
            $t->string('invoice_series', 10)->nullable()->after('fiscal_year');
        });
        Schema::table('invoice_number_events', function (Blueprint $t) {
            $t->string('fiscal_year', 7)->nullable()->after('sequence_value');
        });
        Schema::table('shop_billing_settings', function (Blueprint $t) {
            $t->string('invoice_series_yearly_from', 7)->nullable()->after('invoice_suffix');
            $t->string('invoice_series_yearly_until', 7)->nullable()->after('invoice_series_yearly_from');
        });

        DB::unprepared(<<<'SQL'
-- The Indian financial year of a local (IST) timestamp, as '2026-27'. Mirrors App\Support\FinancialYear.
CREATE OR REPLACE FUNCTION fiscal_year_of(p_at timestamp) RETURNS text AS $$
DECLARE v_start int;
BEGIN
    v_start := CASE WHEN extract(month FROM p_at) >= 4 THEN extract(year FROM p_at)::int ELSE extract(year FROM p_at)::int - 1 END;
    RETURN v_start::text || '-' || lpad(((v_start + 1) % 100)::text, 2, '0');
END;
$$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION ist_now() RETURNS timestamp AS $$
    SELECT (now() AT TIME ZONE 'Asia/Kolkata')::timestamp;
$$ LANGUAGE sql STABLE;

-- 'yearly' when the shop restarts numbers in this year AND its prefix/suffix shows the year; else 'continuous'.
CREATE OR REPLACE FUNCTION invoice_series_for(p_shop_id bigint, p_fy text) RETURNS text AS $$
DECLARE s record;
BEGIN
    SELECT invoice_prefix, invoice_suffix, invoice_series_yearly_from AS y_from, invoice_series_yearly_until AS y_until
      INTO s FROM shop_billing_settings WHERE shop_id = p_shop_id LIMIT 1;
    IF s IS NULL OR s.y_from IS NULL OR p_fy < s.y_from OR (s.y_until IS NOT NULL AND p_fy >= s.y_until) THEN
        RETURN 'continuous';
    END IF;
    IF position('{FY}' IN COALESCE(s.invoice_prefix, '') || COALESCE(s.invoice_suffix, '')) = 0 THEN
        RETURN 'continuous';
    END IF;
    RETURN 'yearly';
END;
$$ LANGUAGE plpgsql STABLE;

-- Prefix/suffix with {FY} replaced by '26-27', then the long-standing character rules.
CREATE OR REPLACE FUNCTION render_invoice_affix(p_affix text, p_fy text, p_is_suffix boolean) RETURNS text AS $$
DECLARE v text;
BEGIN
    v := replace(btrim(COALESCE(p_affix, '')), '{FY}', substr(p_fy, 3, 2) || '-' || substr(p_fy, 6, 2));
    IF p_is_suffix THEN
        RETURN regexp_replace(v, '[^A-Za-z0-9\-/\.]', '', 'g');
    END IF;
    v := regexp_replace(v, '[^A-Za-z0-9\-/]', '', 'g');
    RETURN CASE WHEN v = '' THEN 'INV-' ELSE v END;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Counters: 'invoice' (continuous) and 'invoice:<fy>' (yearly) both start at the shop's starting number.
CREATE OR REPLACE FUNCTION next_shop_counter(p_shop_id bigint, p_counter_key text)
RETURNS bigint AS $$
DECLARE
    v_current bigint;
    v_next bigint;
    v_initial bigint := 0;
BEGIN
    IF p_counter_key = 'invoice' OR p_counter_key LIKE 'invoice:%' THEN
        SELECT (invoice_start_number - 1) INTO v_initial FROM shop_billing_settings WHERE shop_id = p_shop_id LIMIT 1;
        IF v_initial IS NULL OR v_initial < 0 THEN
            v_initial := 1000;
        END IF;
    END IF;

    INSERT INTO shop_counters (shop_id, counter_key, current_value, created_at, updated_at)
    VALUES (p_shop_id, p_counter_key, v_initial, now(), now())
    ON CONFLICT (shop_id, counter_key) DO NOTHING;

    SELECT current_value INTO v_current FROM shop_counters
    WHERE shop_id = p_shop_id AND counter_key = p_counter_key FOR UPDATE;

    IF v_current IS NULL THEN
        RAISE EXCEPTION 'Counter not found for shop % key %', p_shop_id, p_counter_key;
    END IF;

    v_next := v_current + 1;
    UPDATE shop_counters SET current_value = v_next, updated_at = now()
    WHERE shop_id = p_shop_id AND counter_key = p_counter_key;

    RETURN v_next;
END;
$$ LANGUAGE plpgsql;

-- The next invoice identity for a shop in a financial year: one implementation for the trigger and PHP.
CREATE OR REPLACE FUNCTION next_invoice_identity(p_shop_id bigint, p_fy text,
    OUT sequence_value bigint, OUT invoice_number text, OUT series text) AS $$
DECLARE s record;
BEGIN
    series := invoice_series_for(p_shop_id, p_fy);
    sequence_value := next_shop_counter(p_shop_id, CASE WHEN series = 'yearly' THEN 'invoice:' || p_fy ELSE 'invoice' END);
    SELECT invoice_prefix, invoice_suffix INTO s FROM shop_billing_settings WHERE shop_id = p_shop_id LIMIT 1;
    invoice_number := render_invoice_affix(COALESCE(s.invoice_prefix, 'INV-'), p_fy, false)
        || sequence_value::text
        || render_invoice_affix(s.invoice_suffix, p_fy, true);
END;
$$ LANGUAGE plpgsql;
SQL);

        // ── Backfill (new columns only) ────────────────────────────────────────────────────────────
        DB::statement("UPDATE invoices SET fiscal_year = fiscal_year_of(COALESCE(finalized_at, created_at, ist_now())), invoice_series = 'continuous' WHERE fiscal_year IS NULL");
        DB::statement(<<<'SQL'
UPDATE invoice_number_events e
   SET fiscal_year = COALESCE(i.fiscal_year, fiscal_year_of(COALESCE(e.created_at, ist_now())))
  FROM (SELECT id, fiscal_year FROM invoices) i
 WHERE e.invoice_id = i.id AND e.fiscal_year IS NULL
SQL);
        DB::statement("UPDATE invoice_number_events SET fiscal_year = fiscal_year_of(COALESCE(created_at, ist_now())) WHERE fiscal_year IS NULL");

        // ── Pre-validation (CONSTITUTION §4 rule 2) ────────────────────────────────────────────────
        foreach ([
            'invoices' => 'SELECT shop_id, fiscal_year, invoice_sequence AS seq FROM invoices WHERE invoice_sequence IS NOT NULL GROUP BY 1, 2, 3 HAVING COUNT(*) > 1',
            'invoice_number_events' => 'SELECT shop_id, fiscal_year, sequence_value AS seq FROM invoice_number_events GROUP BY 1, 2, 3 HAVING COUNT(*) > 1',
        ] as $table => $sql) {
            $dupes = DB::select($sql);
            if ($dupes !== []) {
                throw new RuntimeException("{$table}: duplicate (shop, financial year, sequence): " . json_encode(array_slice($dupes, 0, 20)));
            }
        }

        DB::statement('ALTER TABLE invoices ALTER COLUMN fiscal_year SET NOT NULL');
        DB::statement('ALTER TABLE invoices ALTER COLUMN invoice_series SET NOT NULL');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_invoice_series_check CHECK (invoice_series IN ('continuous', 'yearly'))");
        DB::statement('ALTER TABLE invoice_number_events ALTER COLUMN fiscal_year SET NOT NULL');

        Schema::table('invoices', function (Blueprint $t) {
            $t->dropUnique('invoices_shop_sequence_unique');
            $t->unique(['shop_id', 'fiscal_year', 'invoice_sequence'], 'invoices_shop_fy_sequence_unique');
        });
        Schema::table('invoice_number_events', function (Blueprint $t) {
            $t->dropUnique('invoice_number_events_shop_sequence_unique');
            $t->unique(['shop_id', 'fiscal_year', 'sequence_value'], 'invoice_number_events_shop_fy_sequence_unique');
        });

        // ── Constitutional trigger #29 ─────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_numbering_guard() RETURNS trigger AS $$
DECLARE
    v_fy text;
    v_id record;
BEGIN
    IF TG_OP = 'INSERT' THEN
        -- The year is the document's own date, never a value the caller sends.
        NEW.fiscal_year := fiscal_year_of(COALESCE(NEW.finalized_at, NEW.created_at, ist_now()));
        IF NEW.invoice_sequence IS NULL THEN
            SELECT * INTO v_id FROM next_invoice_identity(NEW.shop_id, NEW.fiscal_year);
            NEW.invoice_sequence := v_id.sequence_value;
            NEW.invoice_series := v_id.series;
            IF NEW.invoice_number IS NULL OR btrim(NEW.invoice_number) = '' THEN
                NEW.invoice_number := v_id.invoice_number;
            END IF;
        ELSE
            NEW.invoice_series := COALESCE(NEW.invoice_series, 'continuous');
        END IF;
        IF NEW.invoice_number IS NULL OR btrim(NEW.invoice_number) = '' THEN
            RAISE EXCEPTION 'invoice_number is required';
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.status IN ('finalized', 'cancelled') THEN
            IF NEW.invoice_number <> OLD.invoice_number THEN
                RAISE EXCEPTION 'invoice_number is immutable after finalization';
            END IF;
            IF NEW.fiscal_year IS DISTINCT FROM OLD.fiscal_year OR NEW.invoice_series IS DISTINCT FROM OLD.invoice_series THEN
                RAISE EXCEPTION 'fiscal_year and invoice_series are immutable after finalization';
            END IF;
            RETURN NEW;
        END IF;

        -- A draft is billed: it belongs to the year it is billed in.
        IF OLD.status = 'draft' AND NEW.status = 'finalized' THEN
            v_fy := fiscal_year_of(COALESCE(NEW.finalized_at, ist_now()));
            IF v_fy <> OLD.fiscal_year THEN
                IF OLD.invoice_series = 'yearly' OR invoice_series_for(NEW.shop_id, v_fy) = 'yearly' THEN
                    SELECT * INTO v_id FROM next_invoice_identity(NEW.shop_id, v_fy);
                    NEW.invoice_sequence := v_id.sequence_value;
                    NEW.invoice_number := v_id.invoice_number;
                    NEW.invoice_series := v_id.series;
                END IF;
                NEW.fiscal_year := v_fy;
            END IF;
            RETURN NEW;
        END IF;

        -- Otherwise a draft keeps the year and series it was numbered in.
        NEW.fiscal_year := OLD.fiscal_year;
        NEW.invoice_series := OLD.invoice_series;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        // ── Constitutional trigger #6 ──────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_numbering_event() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO invoice_number_events (shop_id, invoice_id, sequence_value, fiscal_year, invoice_number, event_type, reason, created_at, updated_at)
        VALUES (NEW.shop_id, NEW.id, NEW.invoice_sequence, NEW.fiscal_year, NEW.invoice_number, 'consumed', null, now(), now())
        ON CONFLICT (shop_id, fiscal_year, sequence_value) DO NOTHING;
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        -- A draft took a new year's number: the old one is accounted for as voided, the new one as consumed.
        IF NEW.invoice_sequence IS DISTINCT FROM OLD.invoice_sequence OR NEW.invoice_number IS DISTINCT FROM OLD.invoice_number THEN
            UPDATE invoice_number_events
               SET event_type = 'voided',
                   reason = 'Draft billed in financial year ' || NEW.fiscal_year || ' as ' || NEW.invoice_number,
                   updated_at = now()
             WHERE shop_id = OLD.shop_id AND fiscal_year = OLD.fiscal_year AND sequence_value = OLD.invoice_sequence;
            INSERT INTO invoice_number_events (shop_id, invoice_id, sequence_value, fiscal_year, invoice_number, event_type, reason, created_at, updated_at)
            VALUES (NEW.shop_id, NEW.id, NEW.invoice_sequence, NEW.fiscal_year, NEW.invoice_number, 'consumed', null, now(), now())
            ON CONFLICT (shop_id, fiscal_year, sequence_value) DO NOTHING;
        ELSIF NEW.fiscal_year IS DISTINCT FROM OLD.fiscal_year THEN
            -- Same continuous number, billed in the next year.
            UPDATE invoice_number_events SET fiscal_year = NEW.fiscal_year, updated_at = now()
             WHERE shop_id = OLD.shop_id AND fiscal_year = OLD.fiscal_year AND sequence_value = OLD.invoice_sequence;
        END IF;

        IF OLD.status = 'finalized' AND NEW.status = 'cancelled' THEN
            INSERT INTO invoice_number_events (shop_id, invoice_id, sequence_value, fiscal_year, invoice_number, event_type, reason, created_at, updated_at)
            VALUES (NEW.shop_id, NEW.id, NEW.invoice_sequence, NEW.fiscal_year, NEW.invoice_number, 'cancelled', NEW.cancellation_reason, now(), now())
            ON CONFLICT (shop_id, fiscal_year, sequence_value) DO UPDATE
                SET event_type = EXCLUDED.event_type, reason = EXCLUDED.reason,
                    invoice_number = EXCLUDED.invoice_number, updated_at = EXCLUDED.updated_at;
        END IF;
        RETURN NEW;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);

        // The broken switch goes; a shop that had it on keeps continuous numbering (its prefix had no {FY}).
        Schema::table('shop_billing_settings', function (Blueprint $t) {
            $t->dropColumn(['year_reset', 'current_fiscal_year']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('shop_billing_settings', function (Blueprint $t) {
            $t->boolean('year_reset')->default(false);
            $t->string('current_fiscal_year', 10)->nullable();
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_numbering_event() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO invoice_number_events (shop_id, invoice_id, sequence_value, invoice_number, event_type, reason, created_at, updated_at)
        VALUES (NEW.shop_id, NEW.id, NEW.invoice_sequence, NEW.invoice_number, 'consumed', null, now(), now())
        ON CONFLICT (shop_id, sequence_value) DO NOTHING;
        RETURN NEW;
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF OLD.status = 'finalized' AND NEW.status = 'cancelled' THEN
            INSERT INTO invoice_number_events (shop_id, invoice_id, sequence_value, invoice_number, event_type, reason, created_at, updated_at)
            VALUES (NEW.shop_id, NEW.id, NEW.invoice_sequence, NEW.invoice_number, 'cancelled', NEW.cancellation_reason, now(), now())
            ON CONFLICT (shop_id, sequence_value) DO UPDATE
                SET event_type = EXCLUDED.event_type, reason = EXCLUDED.reason,
                    invoice_number = EXCLUDED.invoice_number, updated_at = EXCLUDED.updated_at;
        END IF;
        RETURN NEW;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION invoices_numbering_guard() RETURNS trigger AS $$
DECLARE
    v_prefix text;
    v_suffix text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.invoice_sequence IS NULL THEN
            NEW.invoice_sequence := next_shop_counter(NEW.shop_id, 'invoice');
        END IF;
        IF NEW.invoice_number IS NULL OR btrim(NEW.invoice_number) = '' THEN
            SELECT invoice_prefix, COALESCE(invoice_suffix, '') INTO v_prefix, v_suffix
            FROM shop_billing_settings WHERE shop_id = NEW.shop_id LIMIT 1;
            v_prefix := btrim(COALESCE(v_prefix, 'INV-'));
            IF v_prefix = '' THEN v_prefix := 'INV-'; END IF;
            v_prefix := regexp_replace(v_prefix, '[^A-Za-z0-9\-/]', '', 'g');
            IF v_prefix = '' THEN v_prefix := 'INV-'; END IF;
            v_suffix := regexp_replace(btrim(COALESCE(v_suffix, '')), '[^A-Za-z0-9\-/\.]', '', 'g');
            NEW.invoice_number := v_prefix || NEW.invoice_sequence::text || v_suffix;
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF OLD.status IN ('finalized', 'cancelled') AND NEW.invoice_number <> OLD.invoice_number THEN
            RAISE EXCEPTION 'invoice_number is immutable after finalization';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        Schema::table('invoice_number_events', function (Blueprint $t) {
            $t->dropUnique('invoice_number_events_shop_fy_sequence_unique');
            $t->unique(['shop_id', 'sequence_value'], 'invoice_number_events_shop_sequence_unique');
            $t->dropColumn('fiscal_year');
        });
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_invoice_series_check');
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropUnique('invoices_shop_fy_sequence_unique');
            $t->unique(['shop_id', 'invoice_sequence'], 'invoices_shop_sequence_unique');
            $t->dropColumn(['fiscal_year', 'invoice_series']);
        });
        Schema::table('shop_billing_settings', function (Blueprint $t) {
            $t->dropColumn(['invoice_series_yearly_from', 'invoice_series_yearly_until']);
        });
        DB::unprepared('DROP FUNCTION IF EXISTS next_invoice_identity(bigint, text); DROP FUNCTION IF EXISTS render_invoice_affix(text, text, boolean); DROP FUNCTION IF EXISTS invoice_series_for(bigint, text); DROP FUNCTION IF EXISTS ist_now(); DROP FUNCTION IF EXISTS fiscal_year_of(timestamp);');
    }
};

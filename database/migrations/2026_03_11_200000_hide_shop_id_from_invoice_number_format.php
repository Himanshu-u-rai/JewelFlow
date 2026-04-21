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

        if (!Schema::hasTable('invoices')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_numbering_guard() RETURNS trigger AS $$
DECLARE
    v_prefix text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.invoice_sequence IS NULL THEN
            NEW.invoice_sequence := next_shop_counter(NEW.shop_id, 'invoice');
        END IF;

        IF NEW.invoice_number IS NULL OR btrim(NEW.invoice_number) = '' THEN
            SELECT invoice_prefix
            INTO v_prefix
            FROM shop_billing_settings
            WHERE shop_id = NEW.shop_id
            LIMIT 1;

            v_prefix := btrim(COALESCE(v_prefix, 'INV-'));
            IF v_prefix = '' THEN
                v_prefix := 'INV-';
            END IF;

            v_prefix := regexp_replace(v_prefix, '[^A-Za-z0-9\-/]', '', 'g');
            IF v_prefix = '' THEN
                v_prefix := 'INV-';
            END IF;

            NEW.invoice_number := v_prefix || lpad(NEW.invoice_sequence::text, 10, '0');
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
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (!Schema::hasTable('invoices')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_numbering_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.invoice_sequence IS NULL THEN
            NEW.invoice_sequence := next_shop_counter(NEW.shop_id, 'invoice');
        END IF;

        IF NEW.invoice_number IS NULL OR trim(NEW.invoice_number) = '' THEN
            NEW.invoice_number := 'INV-' || NEW.shop_id || '-' || lpad(NEW.invoice_sequence::text, 10, '0');
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
    }
};


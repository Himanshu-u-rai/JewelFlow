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

        // Fix the trigger: remove lpad zero-padding, add suffix support
        DB::unprepared(<<<'SQL'
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
            SELECT invoice_prefix, COALESCE(invoice_suffix, '')
            INTO v_prefix, v_suffix
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

        // Temporarily disable all triggers to fix historical data
        DB::statement('ALTER TABLE invoices DISABLE TRIGGER invoices_accounting_guard_trigger');
        DB::statement('ALTER TABLE invoices DISABLE TRIGGER invoices_numbering_guard_trigger');

        // Fix existing invoices: strip leading zeros from the numeric portion
        // Pattern: prefix (letters/hyphens/slashes) + zeros + digits = prefix + digits
        DB::statement(<<<'SQL'
            UPDATE invoices
            SET invoice_number = regexp_replace(
                invoice_number,
                '^([A-Za-z\-/]+)0+([1-9]\d*)$',
                '\1\2'
            )
            WHERE invoice_number ~ '^[A-Za-z\-/]+0{2,}\d+$'
SQL);

        // Re-enable all triggers
        DB::statement('ALTER TABLE invoices ENABLE TRIGGER invoices_numbering_guard_trigger');
        DB::statement('ALTER TABLE invoices ENABLE TRIGGER invoices_accounting_guard_trigger');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (!Schema::hasTable('invoices')) {
            return;
        }

        // Revert to the padded version
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
};

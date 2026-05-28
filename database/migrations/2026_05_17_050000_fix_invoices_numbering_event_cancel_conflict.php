<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix invoice reversal hitting a unique-violation in the numbering-events trigger.
     *
     * The existing `invoices_numbering_event` trigger writes a 'consumed' event on every
     * INSERT (with ON CONFLICT DO NOTHING) and a 'cancelled' event on the finalized→cancelled
     * transition — but the cancelled INSERT had NO conflict clause, while the unique
     * constraint (shop_id, sequence_value) only permits one row per sequence. Result: the
     * second INSERT during reversal blew up with `duplicate key value violates unique
     * constraint "invoice_number_events_shop_sequence_unique"`.
     *
     * Fix: use ON CONFLICT DO UPDATE on the cancelled-event INSERT so it overwrites the
     * existing row's event_type/reason/updated_at. The (shop_id, sequence_value) row stays
     * unique and reflects the latest fate of the sequence number. We deliberately keep
     * the unique constraint — it's an audit safeguard against assigning the same sequence
     * to two invoices.
     */
    public function up(): void
    {
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
                SET event_type     = EXCLUDED.event_type,
                    reason         = EXCLUDED.reason,
                    invoice_number = EXCLUDED.invoice_number,
                    updated_at     = EXCLUDED.updated_at;
        END IF;
        RETURN NEW;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        // Restore the prior trigger body (no ON CONFLICT on the cancelled insert).
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
            VALUES (NEW.shop_id, NEW.id, NEW.invoice_sequence, NEW.invoice_number, 'cancelled', NEW.cancellation_reason, now(), now());
        END IF;
        RETURN NEW;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);
    }
};

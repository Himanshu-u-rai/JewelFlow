<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit notes — the accounting document for a settled return. Modelled
     * intentionally close to `invoices`: same shape, same numeric identity
     * (total = subtotal + gst + wastage - discount + round_off), but its own
     * legal sequence space (CN-NNNN) per shop, and POSITIVE values throughout
     * (the credit amount, not negated like the old mirror-invoice approach).
     *
     * Companion `credit_note_number_events` records the consumed sequence
     * numbers (audit-grade gap-checking, mirrors invoice_number_events).
     */
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('return_order_id')->unique()->constrained('return_orders')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // Legal numbering — independent sequence per shop. Set by the
            // CreditNoteService via BusinessIdentifierService::nextCreditNoteIdentifier.
            $table->unsignedBigInteger('credit_note_sequence');
            $table->string('credit_note_number');

            // Money shape mirrors invoices. Stored as POSITIVE values — the
            // amount being credited to the customer.
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('gst', 18, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('wastage_charge', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('round_off', 10, 4)->default(0);
            $table->decimal('total', 18, 2);

            // Lifecycle. Single state for Phase 1 — 'issued' is the only legal
            // value; emit-on-settle, immutable thereafter.
            $table->string('status', 16)->default('issued');
            $table->timestamp('issued_at');
            $table->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'credit_note_sequence'], 'credit_notes_shop_sequence_unique');
            $table->unique(['shop_id', 'credit_note_number'],   'credit_notes_shop_number_unique');
            $table->index(['shop_id', 'invoice_id']);
            $table->index(['shop_id', 'issued_at']);
        });

        // status CHECK — 'issued' only in Phase 1.
        DB::statement(
            "ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_status_check "
            . "CHECK (status IN ('issued'))"
        );

        // Accounting identity guard — total = subtotal + gst + wastage - discount + round_off,
        // verified to 2dp. Same pattern as invoices_accounting_guard.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION credit_notes_accounting_guard() RETURNS trigger AS $$
BEGIN
    IF ROUND(NEW.total::numeric, 2) <> ROUND((COALESCE(NEW.subtotal,0) + COALESCE(NEW.gst,0) + COALESCE(NEW.wastage_charge,0) - COALESCE(NEW.discount,0) + COALESCE(NEW.round_off,0))::numeric, 2) THEN
        RAISE EXCEPTION 'Credit note total mismatch: total %, expected %',
            ROUND(NEW.total::numeric, 2),
            ROUND((COALESCE(NEW.subtotal,0) + COALESCE(NEW.gst,0) + COALESCE(NEW.wastage_charge,0) - COALESCE(NEW.discount,0) + COALESCE(NEW.round_off,0))::numeric, 2);
    END IF;

    -- Overflow guard: cumulative credit notes against an invoice cannot exceed
    -- the invoice's total. Defends against double-issuing CNs that together
    -- refund more than the customer paid.
    IF TG_OP = 'INSERT' THEN
        IF (
            SELECT COALESCE(SUM(total), 0) FROM credit_notes WHERE invoice_id = NEW.invoice_id
        ) + NEW.total > (
            SELECT ROUND(total::numeric, 2) FROM invoices WHERE id = NEW.invoice_id
        ) + 0.01 THEN
            RAISE EXCEPTION 'Credit notes against invoice % would exceed invoice total', NEW.invoice_id;
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_notes_accounting_guard_trigger
    BEFORE INSERT OR UPDATE ON credit_notes
    FOR EACH ROW EXECUTE FUNCTION credit_notes_accounting_guard();
SQL);

        // Numbering events — legal audit trail of which sequence numbers were
        // consumed in which shop. Mirrors invoice_number_events.
        Schema::create('credit_note_number_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('credit_note_id')->nullable()->constrained('credit_notes')->restrictOnDelete();
            $table->unsignedBigInteger('sequence_value');
            $table->string('credit_note_number');
            $table->string('event_type'); // 'consumed' (only value in Phase 1)
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'sequence_value'], 'credit_note_number_events_shop_sequence_unique');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION credit_notes_numbering_event() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO credit_note_number_events (shop_id, credit_note_id, sequence_value, credit_note_number, event_type, reason, created_at, updated_at)
        VALUES (NEW.shop_id, NEW.id, NEW.credit_note_sequence, NEW.credit_note_number, 'consumed', null, now(), now())
        ON CONFLICT (shop_id, sequence_value) DO NOTHING;
        RETURN NEW;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_notes_numbering_event_trigger
    AFTER INSERT ON credit_notes
    FOR EACH ROW EXECUTE FUNCTION credit_notes_numbering_event();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS credit_notes_numbering_event_trigger ON credit_notes');
        DB::statement('DROP FUNCTION IF EXISTS credit_notes_numbering_event()');
        DB::statement('DROP TRIGGER IF EXISTS credit_notes_accounting_guard_trigger ON credit_notes');
        DB::statement('DROP FUNCTION IF EXISTS credit_notes_accounting_guard()');
        Schema::dropIfExists('credit_note_number_events');
        Schema::dropIfExists('credit_notes');
    }
};

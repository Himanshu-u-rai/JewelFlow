<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addInvoiceSequenceColumns();
        $this->addAuditHashColumns();
        $this->createInvoiceNumberEventsTable();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement("DROP TRIGGER IF EXISTS invoices_immutable_trigger ON invoices");
        DB::statement("DROP FUNCTION IF EXISTS enforce_invoice_immutability()");

        $this->backfillHashesAndSequences();
        $this->createAccountingTriggers();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'invoice_items_finalized_guard_trigger ON invoice_items',
                'audit_logs_append_only_trigger ON audit_logs',
                'audit_logs_hash_trigger ON audit_logs',
                'invoices_accounting_guard_trigger ON invoices',
                'invoices_numbering_guard_trigger ON invoices',
                'invoices_numbering_event_trigger ON invoices',
            ] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            foreach ([
                'invoice_items_finalized_guard',
                'audit_logs_append_only_guard',
                'audit_logs_hash_chain',
                'invoices_accounting_guard',
                'invoices_numbering_guard',
                'invoices_numbering_event',
            ] as $fn) {
                DB::statement("DROP FUNCTION IF EXISTS {$fn}()");
            }

            DB::statement('DROP SEQUENCE IF EXISTS invoice_number_seq');
        }
        Schema::dropIfExists('invoice_number_events');

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (Schema::hasColumn('invoices', 'invoice_sequence')) {
                    $table->dropColumn('invoice_sequence');
                }
            });
        }

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (Schema::hasColumn('audit_logs', 'prev_hash')) {
                    $table->dropColumn('prev_hash');
                }
                if (Schema::hasColumn('audit_logs', 'row_hash')) {
                    $table->dropColumn('row_hash');
                }
            });
        }
    }

    private function addInvoiceSequenceColumns(): void
    {
        if (!Schema::hasColumn('invoices', 'invoice_sequence')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('invoice_sequence')->nullable()->after('invoice_number');
                $table->unique(['shop_id', 'invoice_sequence'], 'invoices_shop_sequence_unique');
                $table->unique(['shop_id', 'invoice_number'], 'invoices_shop_number_unique');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS invoice_number_seq START 1 INCREMENT 1');
        }
    }

    private function addAuditHashColumns(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('audit_logs', 'prev_hash')) {
                $table->string('prev_hash', 128)->nullable()->after('user_agent');
            }
            if (!Schema::hasColumn('audit_logs', 'row_hash')) {
                $table->string('row_hash', 128)->nullable()->after('prev_hash');
            }
        });
    }

    private function createInvoiceNumberEventsTable(): void
    {
        if (Schema::hasTable('invoice_number_events')) {
            return;
        }

        Schema::create('invoice_number_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->unsignedBigInteger('sequence_value');
            $table->string('invoice_number');
            $table->string('event_type'); // consumed, cancelled
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'sequence_value'], 'invoice_number_events_shop_sequence_unique');
            $table->index(['shop_id', 'invoice_id']);
        });
    }

    private function createAccountingTriggers(): void
    {
        // Replace legacy invoice immutability trigger with the accounting-grade guard set.
        DB::statement("DROP TRIGGER IF EXISTS invoices_immutable_trigger ON invoices");
        DB::statement("DROP FUNCTION IF EXISTS enforce_invoice_immutability()");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoice_items_finalized_guard() RETURNS trigger AS $$
DECLARE
    inv_status text;
BEGIN
    SELECT status INTO inv_status FROM invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
    IF inv_status IN ('finalized', 'cancelled') THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement("DROP TRIGGER IF EXISTS invoice_items_finalized_guard_trigger ON invoice_items");
        DB::statement("CREATE TRIGGER invoice_items_finalized_guard_trigger BEFORE INSERT OR UPDATE OR DELETE ON invoice_items FOR EACH ROW EXECUTE FUNCTION invoice_items_finalized_guard()");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_numbering_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.invoice_sequence IS NULL THEN
            NEW.invoice_sequence := nextval('invoice_number_seq');
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
        DB::statement("DROP TRIGGER IF EXISTS invoices_numbering_guard_trigger ON invoices");
        DB::statement("CREATE TRIGGER invoices_numbering_guard_trigger BEFORE INSERT OR UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION invoices_numbering_guard()");

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
        DB::statement("DROP TRIGGER IF EXISTS invoices_numbering_event_trigger ON invoices");
        DB::statement("CREATE TRIGGER invoices_numbering_event_trigger AFTER INSERT OR UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION invoices_numbering_event()");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_accounting_guard() RETURNS trigger AS $$
DECLARE
    lock_date date;
    item_sum numeric(18,2);
    item_count bigint;
    expected_total numeric(18,2);
BEGIN
    SELECT financial_lock_date INTO lock_date FROM shop_rules WHERE shop_id = NEW.shop_id;

    IF lock_date IS NOT NULL THEN
        IF TG_OP = 'INSERT' AND NEW.status = 'finalized' AND NEW.created_at::date <= lock_date THEN
            RAISE EXCEPTION 'Financial lock active through %, invoice date %', lock_date, NEW.created_at::date;
        END IF;
        IF TG_OP = 'UPDATE' AND OLD.status = 'finalized' AND NEW.status = 'cancelled' AND OLD.created_at::date <= lock_date THEN
            RAISE EXCEPTION 'Financial lock active through %, reversal blocked for invoice date %', lock_date, OLD.created_at::date;
        END IF;
    END IF;

    IF (TG_OP = 'INSERT' AND NEW.status = 'finalized')
        OR (TG_OP = 'UPDATE' AND OLD.status = 'draft' AND NEW.status = 'finalized') THEN

        SELECT COALESCE(SUM(line_total), 0), COUNT(*) INTO item_sum, item_count
        FROM invoice_items
        WHERE invoice_id = NEW.id;

        IF item_count > 0 AND ROUND(NEW.subtotal::numeric, 2) <> ROUND(item_sum::numeric, 2) THEN
            RAISE EXCEPTION 'Invoice subtotal mismatch: subtotal %, item_sum %', NEW.subtotal, item_sum;
        END IF;

        expected_total := ROUND(COALESCE(NEW.subtotal,0) + COALESCE(NEW.gst,0) + COALESCE(NEW.wastage_charge,0), 2);
        IF ROUND(NEW.total::numeric, 2) <> expected_total THEN
            RAISE EXCEPTION 'Invoice total mismatch: total %, expected %', NEW.total, expected_total;
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement("DROP TRIGGER IF EXISTS invoices_accounting_guard_trigger ON invoices");
        DB::statement("CREATE TRIGGER invoices_accounting_guard_trigger BEFORE INSERT OR UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION invoices_accounting_guard()");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_logs_append_only_guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'audit_logs is append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement("DROP TRIGGER IF EXISTS audit_logs_append_only_trigger ON audit_logs");
        DB::statement("CREATE TRIGGER audit_logs_append_only_trigger BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only_guard()");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_logs_hash_chain() RETURNS trigger AS $$
DECLARE
    prev text;
BEGIN
    SELECT row_hash INTO prev
    FROM audit_logs
    WHERE shop_id = NEW.shop_id
    ORDER BY id DESC
    LIMIT 1;

    NEW.prev_hash := prev;
    NEW.row_hash := encode(
        digest(
            coalesce(NEW.prev_hash, '') || '|' ||
            coalesce(NEW.actor::text, '') || '|' ||
            coalesce(NEW.target::text, '') || '|' ||
            coalesce(NEW.before::text, '') || '|' ||
            coalesce(NEW.after::text, '') || '|' ||
            coalesce(NEW.created_at::text, now()::text),
            'sha256'
        ),
        'hex'
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement("DROP TRIGGER IF EXISTS audit_logs_hash_trigger ON audit_logs");
        DB::statement("CREATE TRIGGER audit_logs_hash_trigger BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_hash_chain()");
    }

    private function backfillHashesAndSequences(): void
    {
        DB::statement("UPDATE invoices SET invoice_sequence = nextval('invoice_number_seq') WHERE invoice_sequence IS NULL");
        DB::statement("UPDATE invoices SET invoice_number = 'INV-' || shop_id || '-' || lpad(invoice_sequence::text, 10, '0') WHERE invoice_number IS NULL OR trim(invoice_number) = ''");

        DB::statement("
            INSERT INTO invoice_number_events (shop_id, invoice_id, sequence_value, invoice_number, event_type, created_at, updated_at)
            SELECT i.shop_id, i.id, i.invoice_sequence, i.invoice_number, 'consumed', now(), now()
            FROM invoices i
            WHERE NOT EXISTS (
                SELECT 1 FROM invoice_number_events e
                WHERE e.shop_id = i.shop_id AND e.sequence_value = i.invoice_sequence
            )
        ");

        // Rebuild hash chain per shop in insertion order.
        $shops = DB::table('audit_logs')->distinct()->pluck('shop_id');
        foreach ($shops as $shopId) {
            $prev = null;
            $rows = DB::table('audit_logs')->where('shop_id', $shopId)->orderBy('id')->get();
            foreach ($rows as $row) {
                $payload = ($prev ?? '') . '|' .
                    ($row->actor ?? '') . '|' .
                    ($row->target ?? '') . '|' .
                    ($row->before ?? '') . '|' .
                    ($row->after ?? '') . '|' .
                    ($row->created_at ?? now()->toDateTimeString());

                $hash = DB::selectOne("SELECT encode(digest(?, 'sha256'), 'hex') AS h", [$payload])->h;
                DB::table('audit_logs')->where('id', $row->id)->update([
                    'prev_hash' => $prev,
                    'row_hash' => $hash,
                ]);
                $prev = $hash;
            }
        }
    }
};

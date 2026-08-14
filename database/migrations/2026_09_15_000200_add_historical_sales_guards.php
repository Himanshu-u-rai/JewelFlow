<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Historical Sales — Batch 1: database-level lifecycle and immutability guards.
 *
 * The application layer is the FIRST line of defence, not the only one. These
 * triggers exist so that a raw `DB::table('historical_sales_documents')->update()`
 * — the exact idiom that already bypasses Eloquent in ReturnService — still
 * cannot rewrite a published historical record.
 *
 * NOTE ON `document_date` IN THE FUTURE (owner-flagged design question).
 * This is enforced by a TRIGGER, not a CHECK constraint, on purpose:
 *
 *   - A CHECK containing CURRENT_DATE is not immutable. PostgreSQL re-validates
 *     CHECK constraints on table rewrites (ALTER TABLE ... SET DATA TYPE, some
 *     ADD COLUMN paths) and pg_restore validates them against restored data. A
 *     row that was perfectly legal when written would make a future migration or
 *     a disaster-recovery restore fail. That is a production outage caused by a
 *     validation rule, which is a bad trade.
 *   - A BEFORE INSERT/UPDATE trigger is evaluated only at write time. pg_restore
 *     replays rows with COPY, and any row that passed at write time still passes
 *     (its date is in the past by then), so restore is safe.
 *   - The trigger allows CURRENT_DATE + 1 day of slack. `CURRENT_DATE` resolves
 *     against the database server's TimeZone, which is not necessarily the shop's
 *     business day. The database is the coarse backstop against absurd dates
 *     (2027, typo'd years); exact business-day validation belongs in the
 *     application, where the shop timezone is known.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->documentGuard();
        $this->lineGuard();
        $this->documentDateGuard();
        $this->batchGuard();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS historical_sales_documents_guard_trg ON historical_sales_documents');
        DB::statement('DROP TRIGGER IF EXISTS historical_sales_documents_date_trg ON historical_sales_documents');
        DB::statement('DROP TRIGGER IF EXISTS historical_sales_lines_guard_trg ON historical_sales_lines');
        DB::statement('DROP TRIGGER IF EXISTS historical_import_batches_guard_trg ON historical_import_batches');
        DB::statement('DROP FUNCTION IF EXISTS historical_sales_documents_guard()');
        DB::statement('DROP FUNCTION IF EXISTS historical_sales_document_date_guard()');
        DB::statement('DROP FUNCTION IF EXISTS historical_sales_lines_guard()');
        DB::statement('DROP FUNCTION IF EXISTS historical_import_batches_guard()');
    }

    /**
     * Published documents are evidence. They may be voided or superseded, and a
     * customer may be linked to them later, but their financial substance can
     * never be edited and they can never be hard-deleted.
     */
    private function documentGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION historical_sales_documents_guard()
            RETURNS trigger AS $$
            DECLARE
                -- Everything NOT in this list is frozen once published. Note the
                -- absence of every money, tax, snapshot and identity column.
                allowed_cols text[] := ARRAY[
                    'status',
                    'customer_id',
                    'superseded_by_document_id',
                    'void_reason',
                    'voided_by',
                    'voided_at',
                    'published_by',
                    'published_at',
                    'updated_at'
                ];
                changed_cols text[];
                col text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION
                            'Historical document % is % and cannot be deleted. Use void or supersede.',
                            OLD.id, OLD.status;
                    END IF;
                    RETURN OLD;
                END IF;

                -- Draft is freely correctable; only the transition is policed.
                IF OLD.status = 'draft' THEN
                    IF NEW.status NOT IN ('draft', 'published', 'void') THEN
                        RAISE EXCEPTION
                            'Invalid historical document transition % -> %', OLD.status, NEW.status;
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status = 'published' AND NEW.status NOT IN ('published', 'superseded', 'void') THEN
                    RAISE EXCEPTION
                        'Invalid historical document transition % -> %', OLD.status, NEW.status;
                END IF;

                IF OLD.status IN ('superseded', 'void') AND NEW.status <> OLD.status THEN
                    RAISE EXCEPTION
                        'Historical document % is terminal (%) and cannot change status', OLD.id, OLD.status;
                END IF;

                SELECT array_agg(d.key) INTO changed_cols
                FROM (
                    SELECT n.key
                    FROM jsonb_each(to_jsonb(NEW)) AS n
                    JOIN jsonb_each(to_jsonb(OLD)) AS o ON o.key = n.key
                    WHERE n.value IS DISTINCT FROM o.value
                ) AS d;

                IF changed_cols IS NOT NULL THEN
                    FOREACH col IN ARRAY changed_cols LOOP
                        IF NOT (col = ANY (allowed_cols)) THEN
                            RAISE EXCEPTION
                                'Published historical document % is immutable: column "%" cannot be modified',
                                OLD.id, col;
                        END IF;
                    END LOOP;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER historical_sales_documents_guard_trg
            BEFORE UPDATE OR DELETE ON historical_sales_documents
            FOR EACH ROW EXECUTE FUNCTION historical_sales_documents_guard()
        SQL);
    }

    /** See the class docblock for why this is a trigger and not a CHECK. */
    private function documentDateGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION historical_sales_document_date_guard()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.document_date > CURRENT_DATE + 1 THEN
                    RAISE EXCEPTION
                        'Historical document date % is in the future (server date %)',
                        NEW.document_date, CURRENT_DATE;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER historical_sales_documents_date_trg
            BEFORE INSERT OR UPDATE OF document_date ON historical_sales_documents
            FOR EACH ROW EXECUTE FUNCTION historical_sales_document_date_guard()
        SQL);
    }

    /**
     * A line belongs to its document's lifecycle. Once the parent is published
     * the line is frozen apart from the advisory inventory link.
     */
    private function lineGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION historical_sales_lines_guard()
            RETURNS trigger AS $$
            DECLARE
                allowed_cols text[] := ARRAY['item_id', 'updated_at'];
                parent_status text;
                changed_cols text[];
                col text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    SELECT status INTO parent_status
                    FROM historical_sales_documents WHERE id = OLD.historical_sales_document_id;

                    -- NULL parent == the document row is already gone in this
                    -- statement (draft delete cascading); nothing to protect.
                    IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
                        RAISE EXCEPTION
                            'Historical line % belongs to % document % and cannot be deleted',
                            OLD.id, parent_status, OLD.historical_sales_document_id;
                    END IF;
                    RETURN OLD;
                END IF;

                SELECT status INTO parent_status
                FROM historical_sales_documents WHERE id = NEW.historical_sales_document_id;

                IF parent_status IS NULL OR parent_status = 'draft' THEN
                    RETURN NEW;
                END IF;

                SELECT array_agg(d.key) INTO changed_cols
                FROM (
                    SELECT n.key
                    FROM jsonb_each(to_jsonb(NEW)) AS n
                    JOIN jsonb_each(to_jsonb(OLD)) AS o ON o.key = n.key
                    WHERE n.value IS DISTINCT FROM o.value
                ) AS d;

                IF changed_cols IS NOT NULL THEN
                    FOREACH col IN ARRAY changed_cols LOOP
                        IF NOT (col = ANY (allowed_cols)) THEN
                            RAISE EXCEPTION
                                'Historical line % is immutable once its document is published: column "%"',
                                OLD.id, col;
                        END IF;
                    END LOOP;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER historical_sales_lines_guard_trg
            BEFORE UPDATE OR DELETE ON historical_sales_lines
            FOR EACH ROW EXECUTE FUNCTION historical_sales_lines_guard()
        SQL);
    }

    /**
     * Batch lifecycle: draft <-> review -> publishing -> published, with cancel
     * available from the unpublished states. `publishing` is the atomic claim; it
     * may fall back to `review` if the publish run fails, so a crashed run does
     * not strand the batch.
     */
    private function batchGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION historical_import_batches_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'published' THEN
                        RAISE EXCEPTION
                            'Published historical import batch % cannot be deleted', OLD.id;
                    END IF;
                    RETURN OLD;
                END IF;

                IF NEW.status = OLD.status THEN
                    RETURN NEW;
                END IF;

                IF NOT (
                    (OLD.status = 'draft'      AND NEW.status IN ('review', 'publishing', 'cancelled'))
                    OR (OLD.status = 'review'     AND NEW.status IN ('draft', 'publishing', 'cancelled'))
                    OR (OLD.status = 'publishing' AND NEW.status IN ('published', 'review'))
                ) THEN
                    RAISE EXCEPTION
                        'Invalid historical import batch transition % -> %', OLD.status, NEW.status;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER historical_import_batches_guard_trg
            BEFORE UPDATE OR DELETE ON historical_import_batches
            FOR EACH ROW EXECUTE FUNCTION historical_import_batches_guard()
        SQL);
    }
};

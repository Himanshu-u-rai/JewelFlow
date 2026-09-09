<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical Sales — Batch 3 foundation correction (4/4).
 *
 * Corrects two defects the Batch 3 foundation audit found in
 * 2026_09_18_000300_create_historical_sales_payments.php (D1 CRITICAL, D3
 * HIGH). That migration's file itself is left untouched — this is a
 * corrective ADD-ON migration, not an amendment.
 *
 * ---------------------------------------------------------------------------
 * D1 — PAYMENT-ROW IMMUTABILITY (CRITICAL).
 *
 * historical_sales_payments had ZERO immutability protection: no Postgres
 * trigger, no ImmutableWhenPublished trait usage, no lifecycle gate. Any
 * code — including a raw DB::table('historical_sales_payments')->update()/
 * ->delete(), which bypasses every Eloquent-layer guard — could silently
 * mutate or delete a payment row belonging to a PUBLISHED document, changing
 * that document's effective paid total while its own frozen
 * paid_amount_snapshot stayed stale. This is the exact class of gap
 * historical_sales_documents_guard()/historical_sales_lines_guard()
 * (2026_09_15_000200) already close for documents and lines.
 *
 * This migration adds `historical_sales_payments_guard()` — a NEW trigger
 * function scoped ONLY to historical_sales_payments. It does not modify,
 * extend, wrap or call either of the two existing guard functions; it is a
 * fully independent function/trigger, mirroring their BEFORE
 * INSERT/UPDATE/DELETE + RAISE EXCEPTION style. Unlike the lines guard (which
 * allow-lists `item_id` as still-mutable post-publish), a payment row has NO
 * legitimately mutable column once published — the row IS the evidence — so
 * the trigger simply rejects INSERT/UPDATE/DELETE outright once the parent
 * document has left `draft`, rather than diffing an allow-list.
 *
 * The trigger additionally enforces, at the DB level (the backstop the
 * app-layer `HistoricalSalesPayment::assertPaymentMethodBelongsToOwnShop()`
 * guard from 7fabc24 cannot reach for raw-SQL writes):
 *   - `historical_sales_document_id` may never be reassigned on an existing
 *     row (parent reassignment forbidden, regardless of either document's
 *     status — the composite `(historical_sales_document_id, shop_id) ->
 *     (id, shop_id)` FK from migration 3 does NOT catch a same-shop
 *     reassignment, only a cross-shop one).
 *   - `shop_payment_method_id`, when set, must reference a
 *     `shop_payment_methods` row belonging to this payment's own `shop_id`.
 *
 * (`shop_id` matching the parent document's `shop_id` is already a hard DB
 * guarantee via migration 3's composite FK
 * `historical_payments_document_shop_foreign` — no tuple
 * `(historical_sales_document_id, shop_id)` can exist unless it matches a
 * real `(id, shop_id)` row in historical_sales_documents. No new check is
 * added here for that; see the falsification test that exercises it.)
 *
 * The application-layer half (`HistoricalSalesPayment::booted()`) gets its
 * own, EARLIER-failing, bespoke `saving`/`deleting` guard in the same commit
 * — deliberately NOT the general-purpose `ImmutableWhenPublished` trait,
 * because that trait's second check depends on `HistoricalLifecycle::
 * unlocked()`, a legitimate escape hatch for documents/lines that published
 * payment evidence must never have. The model guard throws a plain
 * `LogicException` with its own message, distinct from whatever class a
 * Postgres trigger exception surfaces as through the PDO/PgSQL driver
 * (`Illuminate\Database\QueryException`) — so removing ONE layer while
 * leaving the other in place is independently observable in tests.
 *
 * ---------------------------------------------------------------------------
 * D3 — MANDATORY ACCOUNT-LABEL SNAPSHOT (HIGH).
 *
 * `account_label_snapshot` was `->nullable()`. It is the ONLY thing that
 * survives a hard-deleted `ShopPaymentMethod` (the FK is `ON DELETE SET
 * NULL` and `shop_payment_methods` has no soft-delete column) — a null/blank
 * snapshot is an unrecoverable loss of the account identity ("HDFC
 * ****4412"), degrading display to a generic mode-derived label with no way
 * back. This is a brand-new table on this branch (no deployed environment
 * has it yet) so there are no real production rows to protect, but the
 * migration still backfills deterministically before adding the constraint,
 * so it stays safe to run against any DB state (including one where test
 * fixtures inserted a null-snapshot row earlier in the same suite run).
 *
 * `was_linked_to_payment_method` is a small, new, write-once-at-creation
 * marker column added alongside this fix. Reasoning: the audit's own
 * suggested minimal fix for the (non-blocking, cosmetic) "No longer active"
 * mislabelling nit was `account_label_snapshot !== null` as the signal for
 * "this row WAS linked to a real account that is now gone". That signal
 * stops working the moment this same migration makes `account_label_
 * snapshot` NOT NULL for every row, including genuinely custom/free-text
 * ones — so the audit's suggested heuristic is invalidated by D3 itself. A
 * one-column, write-once, never-updated boolean captured at INSERT time
 * (see `HistoricalSalesPayment::booted()`) is the smallest correct
 * replacement signal; it is not schema work disproportionate to the problem
 * it solves, and it lets `displayAccountLabel()` distinguish "was linked,
 * now gone" (append "(No longer active)") from "always custom, never linked"
 * (show the label plain) without guessing from mutable state.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->lockAccountLabelSnapshot();
        $this->addLinkedPaymentMethodMarker();
        $this->addPaymentsImmutabilityGuard();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS historical_sales_payments_guard_trg ON historical_sales_payments');
        DB::statement('DROP FUNCTION IF EXISTS historical_sales_payments_guard()');

        Schema::table('historical_sales_payments', function (Blueprint $table): void {
            $table->dropColumn('was_linked_to_payment_method');
        });

        DB::statement(
            'ALTER TABLE historical_sales_payments '
            . 'DROP CONSTRAINT IF EXISTS historical_payments_account_label_snapshot_not_blank_check'
        );
        DB::statement('ALTER TABLE historical_sales_payments ALTER COLUMN account_label_snapshot DROP NOT NULL');
    }

    /**
     * D3. Deterministic backfill first (never destructive — only touches
     * null/blank rows), then NOT NULL, then a non-blank CHECK (rejects the
     * empty string too, not just NULL).
     */
    private function lockAccountLabelSnapshot(): void
    {
        DB::statement(<<<'SQL'
            UPDATE historical_sales_payments
            SET account_label_snapshot = INITCAP(REPLACE(mode, '_', ' '))
            WHERE account_label_snapshot IS NULL OR TRIM(account_label_snapshot) = ''
        SQL);

        DB::statement('ALTER TABLE historical_sales_payments ALTER COLUMN account_label_snapshot SET NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_payments
            ADD CONSTRAINT historical_payments_account_label_snapshot_not_blank_check
            CHECK (TRIM(account_label_snapshot) <> '')
        SQL);
    }

    /**
     * D3 cosmetic-nit enablement — see class docblock for why a persisted
     * marker replaces the audit's original (now-invalidated) heuristic.
     * Backfilled deterministically from the column D1/D3 predates
     * (`shop_payment_method_id`) for any row that might already exist.
     */
    private function addLinkedPaymentMethodMarker(): void
    {
        Schema::table('historical_sales_payments', function (Blueprint $table): void {
            $table->boolean('was_linked_to_payment_method')->default(false)->after('shop_payment_method_id');
        });

        DB::statement(<<<'SQL'
            UPDATE historical_sales_payments
            SET was_linked_to_payment_method = TRUE
            WHERE shop_payment_method_id IS NOT NULL
        SQL);
    }

    /**
     * D1. See class docblock for the full contract. A fully separate
     * function/trigger from historical_sales_documents_guard()/
     * historical_sales_lines_guard() — neither of those is touched.
     */
    private function addPaymentsImmutabilityGuard(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION historical_sales_payments_guard()
            RETURNS trigger AS $$
            DECLARE
                parent_status text;
                method_shop_id bigint;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    SELECT status INTO parent_status
                    FROM historical_sales_documents WHERE id = OLD.historical_sales_document_id;

                    -- NULL parent == the document row is already gone in this
                    -- statement (draft delete cascading); nothing to protect.
                    IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
                        RAISE EXCEPTION
                            'Historical payment % belongs to % document % and cannot be deleted',
                            OLD.id, parent_status, OLD.historical_sales_document_id;
                    END IF;
                    RETURN OLD;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.historical_sales_document_id IS DISTINCT FROM OLD.historical_sales_document_id THEN
                        RAISE EXCEPTION
                            'Historical payment % cannot be reassigned to a different document (% -> %)',
                            OLD.id, OLD.historical_sales_document_id, NEW.historical_sales_document_id;
                    END IF;

                    SELECT status INTO parent_status
                    FROM historical_sales_documents WHERE id = OLD.historical_sales_document_id;

                    IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
                        RAISE EXCEPTION
                            'Historical payment % is immutable once its document (%) is published',
                            OLD.id, parent_status;
                    END IF;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    SELECT status INTO parent_status
                    FROM historical_sales_documents WHERE id = NEW.historical_sales_document_id;

                    IF parent_status IS NULL THEN
                        RAISE EXCEPTION
                            'Historical payment references a non-existent document %',
                            NEW.historical_sales_document_id;
                    END IF;

                    IF parent_status <> 'draft' THEN
                        RAISE EXCEPTION
                            'Cannot insert a historical payment into % document %',
                            parent_status, NEW.historical_sales_document_id;
                    END IF;
                END IF;

                IF NEW.shop_payment_method_id IS NOT NULL THEN
                    SELECT shop_id INTO method_shop_id
                    FROM shop_payment_methods WHERE id = NEW.shop_payment_method_id;

                    IF method_shop_id IS NOT NULL AND method_shop_id <> NEW.shop_id THEN
                        RAISE EXCEPTION
                            'Historical payment % cannot reference payment method % from another shop',
                            COALESCE(NEW.id, 0), NEW.shop_payment_method_id;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER historical_sales_payments_guard_trg
            BEFORE INSERT OR UPDATE OR DELETE ON historical_sales_payments
            FOR EACH ROW EXECUTE FUNCTION historical_sales_payments_guard()
        SQL);
    }
};

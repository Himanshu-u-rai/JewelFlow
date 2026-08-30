<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical Sales — Batch 3 foundation (3/3): per-tender payment rows, per
 * HISTORICAL-BATCH-3-UX-CONTRACT-V2 §7/§8.
 *
 * NOT jsonb. Payment rows need the same independent editability/deletability
 * `historical_sales_lines` already has (Agent F's explicit recommendation),
 * so this mirrors that table's exact one-to-many/composite-FK/cascade pattern
 * rather than inventing a second convention.
 *
 * MODE VOCABULARY reuses `InvoicePayment::VALID_MODES` (9 canonical modes) —
 * the vocabulary only. `InvoicePayment` itself is NEVER reused: it is live,
 * immutable-append, and wired to the cashbook. Nothing in this table writes
 * to `invoice_payments`, `cash_transactions`, `store_credit_movements`, or any
 * other live ledger. See HISTORICAL-BATCH-3-UX-CONTRACT-V2 §13.
 *
 * SNAPSHOT, NOT LIVE DEPENDENCY. `shop_payment_method_id` is an OPTIONAL,
 * read-only reference for convenience/reporting — the label snapshot is what
 * every read path displays. `shop_payment_methods` has no soft-delete column,
 * so a live method really can be hard-deleted. Unlike the composite
 * `(child_id, shop_id) -> (id, shop_id)` FK pattern used elsewhere in this
 * module, this reference is deliberately a PLAIN single-column FK to
 * `shop_payment_methods.id` with `ON DELETE SET NULL`: a composite FK's
 * `SET NULL` action nulls EVERY column in the FK, including `shop_id` (proven
 * empirically — Postgres has no "null just this column" mode), which would
 * corrupt this row's own tenant scope the moment an unrelated payment method
 * elsewhere is deleted. Cross-shop reference integrity (this method really
 * belongs to this row's shop) is therefore enforced by
 * `HistoricalSalesPayment`'s model guard, not the FK. The
 * account_label_snapshot column (mirroring the existing
 * `payment_method_label_snapshot` precedent) is what survives that deletion
 * and is what the UI is expected to render as "No longer active" when the FK
 * is null but a snapshot exists.
 *
 * IMMUTABILITY. This table is NOT touched by the documents/lines Postgres
 * triggers (2026_09_15_000200) at all — those only guard
 * historical_sales_documents/lines. Payment rows belong to a draft document's
 * editable workflow; freezing them after publish is an application-layer
 * concern for the lifecycle service to enforce (same as the rest of the
 * draft-only payment CRUD scope), not a new trigger in this migration.
 */
return new class extends Migration
{
    private const VALID_MODES = [
        'cash', 'upi', 'bank', 'wallet', 'old_gold', 'old_silver', 'other', 'emi', 'scheme',
    ];

    public function up(): void
    {
        $this->createPayments();
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_sales_payments');
    }

    private function createPayments(): void
    {
        Schema::create('historical_sales_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->unsignedBigInteger('historical_sales_document_id');

            $table->string('mode');
            // Nullable: a custom/free-text mode+account combination has no FK.
            $table->unsignedBigInteger('shop_payment_method_id')->nullable();
            // Reuses the `payment_method_label_snapshot` precedent — authoritative
            // for display regardless of what happens to the live method row.
            $table->string('account_label_snapshot')->nullable();

            $table->decimal('amount', 18, 2);
            $table->date('payment_date')->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['shop_id']);
        });

        DB::statement(
            'CREATE INDEX historical_payments_document_shop_index '
            . 'ON historical_sales_payments (historical_sales_document_id, shop_id)'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_payments
            ADD CONSTRAINT historical_payments_document_shop_foreign
            FOREIGN KEY (historical_sales_document_id, shop_id)
            REFERENCES historical_sales_documents (id, shop_id) ON DELETE CASCADE
        SQL);

        DB::statement('CREATE INDEX historical_payments_method_index ON historical_sales_payments (shop_payment_method_id)');
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_payments
            ADD CONSTRAINT historical_payments_method_foreign
            FOREIGN KEY (shop_payment_method_id)
            REFERENCES shop_payment_methods (id) ON DELETE SET NULL
        SQL);

        $modes = "'" . implode("', '", self::VALID_MODES) . "'";
        DB::statement(
            'ALTER TABLE historical_sales_payments ADD CONSTRAINT historical_payments_mode_check '
            . "CHECK (mode IN ({$modes}))"
        );

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_payments
            ADD CONSTRAINT historical_payments_non_negative_check
            CHECK (amount >= 0)
        SQL);
    }
};

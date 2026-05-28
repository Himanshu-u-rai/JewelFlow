<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — customer store-credit wallet.
     *
     * Append-only ledger. Balance is *derived* (SUM of amount per customer/shop),
     * never stored. A BEFORE-INSERT trigger blocks any movement that would
     * push the running balance negative — hard guarantee against overdraft.
     *
     * `source_type` enum:
     *   credit_note_issued — refund taken as store credit instead of cash
     *   sale_applied       — credit consumed against an invoice payment
     *   manual_adjustment  — owner adjusts balance (goodwill, correction)
     *   expiry             — scheduled job zeros out an expired balance slice
     *   reversal           — reverses an earlier movement (rare; admin)
     */
    public function up(): void
    {
        Schema::create('store_credit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // Signed: positive = credit added, negative = credit consumed.
            $table->decimal('amount', 18, 2);

            // What kind of event caused this movement.
            $table->string('source_type', 32);
            // Polymorphic-style pointer to the originating row, depending on source_type.
            // credit_note_issued → credit_notes.id
            // sale_applied       → invoices.id
            // manual_adjustment  → null (notes contains rationale)
            // expiry             → store_credit_movements.id of the original credit
            // reversal           → store_credit_movements.id of the row being reversed
            $table->unsignedBigInteger('source_id')->nullable();

            // Optional expiry — when this credit slice expires. A daily job
            // emits an `expiry` movement zeroing out anything past its date.
            $table->timestamp('expires_at')->nullable();

            $table->text('notes')->nullable();

            // Who did this. user_id is required (system or human).
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // approved_by required for manual_adjustment per service-level guard.
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['shop_id', 'customer_id', 'created_at']);
            $table->index(['shop_id', 'expires_at']);
        });

        DB::statement(
            "ALTER TABLE store_credit_movements ADD CONSTRAINT store_credit_movements_source_check "
            . "CHECK (source_type IN ('credit_note_issued','sale_applied','manual_adjustment','expiry','reversal'))"
        );

        // Non-negative balance guard. Computes running total per (shop, customer)
        // and raises if the new row would push it below zero. Triggers fire
        // INSIDE the inserting transaction — the row hasn't committed yet, so
        // we have to include NEW.amount in the projection.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION store_credit_non_negative_guard() RETURNS trigger AS $$
DECLARE
    running_total numeric;
BEGIN
    SELECT COALESCE(SUM(amount), 0)
      INTO running_total
      FROM store_credit_movements
     WHERE shop_id = NEW.shop_id
       AND customer_id = NEW.customer_id;

    IF (running_total + NEW.amount) < 0 THEN
        RAISE EXCEPTION
            'Store credit overdraft: customer % balance % cannot absorb movement %',
            NEW.customer_id, running_total, NEW.amount;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER store_credit_non_negative_guard_trigger
    BEFORE INSERT ON store_credit_movements
    FOR EACH ROW EXECUTE FUNCTION store_credit_non_negative_guard();
SQL);

        // Append-only guard: no UPDATE/DELETE. Mirrors the cash_transactions guard.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION store_credit_append_only_guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Ledger table store_credit_movements is append-only';
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER store_credit_append_only_guard_trigger
    BEFORE UPDATE OR DELETE ON store_credit_movements
    FOR EACH ROW EXECUTE FUNCTION store_credit_append_only_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS store_credit_append_only_guard_trigger ON store_credit_movements');
        DB::statement('DROP FUNCTION IF EXISTS store_credit_append_only_guard()');
        DB::statement('DROP TRIGGER IF EXISTS store_credit_non_negative_guard_trigger ON store_credit_movements');
        DB::statement('DROP FUNCTION IF EXISTS store_credit_non_negative_guard()');
        Schema::dropIfExists('store_credit_movements');
    }
};

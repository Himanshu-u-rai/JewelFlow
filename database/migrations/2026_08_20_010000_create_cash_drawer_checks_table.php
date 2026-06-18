<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash Book Phase 3 — "Match your drawer".
 *
 * An append-only log of physical cash counts. Each row records what the system
 * expected the cash drawer to hold (computed from cash_transactions at the
 * moment of the check) versus what the owner physically counted, plus the
 * difference and an optional note. Multiple checks per day are allowed.
 *
 * This NEVER mutates cash_transactions. It is supporting/observational data:
 * the cash ledger remains the source of truth; a drawer check is a snapshot of
 * "we counted the drawer and here is how it compared." Append-only (no UPDATE/
 * DELETE) so the count history is a faithful record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_drawer_checks')) {
            Schema::create('cash_drawer_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shop_id')->constrained('shops');
                $table->date('business_date');
                // Computed cash-in-hand at check time (system expectation).
                $table->decimal('expected_cash', 18, 2);
                // What the owner physically counted in the drawer.
                $table->decimal('counted_cash', 18, 2);
                // counted - expected. Positive = drawer over; negative = short.
                $table->decimal('difference', 18, 2);
                $table->text('note')->nullable();
                $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();

                $table->index(['shop_id', 'business_date']);
                $table->index(['shop_id', 'created_at']);
            });
        }

        // Append-only guard — drawer-count history must never be edited or
        // deleted (mirrors the other ledger guards, Constitution Article I).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_drawer_checks_append_only_guard()
            RETURNS trigger AS $$
            BEGIN
              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION 'Append-only: cash_drawer_checks rows cannot be updated (id=%)', OLD.id;
              ELSIF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Append-only: cash_drawer_checks rows cannot be deleted (id=%)', OLD.id;
              END IF;
              RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS cash_drawer_checks_append_only_trigger ON cash_drawer_checks;

            CREATE TRIGGER cash_drawer_checks_append_only_trigger
            BEFORE UPDATE OR DELETE ON cash_drawer_checks
            FOR EACH ROW EXECUTE FUNCTION cash_drawer_checks_append_only_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cash_drawer_checks_append_only_trigger ON cash_drawer_checks;
            DROP FUNCTION IF EXISTS cash_drawer_checks_append_only_guard();
        SQL);

        Schema::dropIfExists('cash_drawer_checks');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B — Migration 3/4 — Stone revaluation events ledger.
 *
 * Every time a stone in inventory is re-valued (owner adjusts unit_value
 * or count), a row is written here. The companion service
 * (StoneRevaluationService) updates stone_components.unit_value and
 * total_value in the same transaction.
 *
 * Constitutional rules:
 *   - Article XIV (Manual Valuation Boundary): the ledger captures
 *     ONLY operator-initiated revaluations. No automated process may
 *     write to this table — enforced at the service-layer entry point.
 *   - Article I (Append-only): rows here are immutable once written.
 *     Migration 040000 installs the append-only trigger (Constitutional
 *     trigger #23).
 *   - Snapshot doctrine: revaluations are only legal while the parent
 *     stone is in inventory (not yet snapshotted to a finalized invoice
 *     or settled return). The service enforces this; the DB cannot
 *     directly enforce parent state from this row.
 *
 * Operationally significant data captured:
 *   - old/new unit_value and count
 *   - delta_total_value (signed: positive = revalued up, negative = down)
 *   - reason (mandatory — operator must justify)
 *   - reevaluated_by_user_id (who did it)
 *   - created_at (when)
 *
 * Query pattern: "show me how this diamond's value has changed since I
 * received it" → ORDER BY created_at on (stone_component_id) joins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stone_revaluation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('stone_component_id')->constrained('stone_components')->cascadeOnDelete();

            // Before/after snapshot
            $table->decimal('old_unit_value',  12, 2);
            $table->integer('old_count');
            $table->decimal('old_total_value', 12, 2);
            $table->decimal('new_unit_value',  12, 2);
            $table->integer('new_count');
            $table->decimal('new_total_value', 12, 2);

            // Delta — derived but stored for forensic clarity.
            $table->decimal('delta_total_value', 12, 2);

            // Justification (mandatory)
            $table->text('reason');

            // Audit trail
            $table->foreignId('reevaluated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['shop_id', 'stone_component_id', 'created_at'], 'stone_revaluation_events_lookup_idx');
        });

        // CHECK constraints (PostgreSQL only) — sanity invariants.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE stone_revaluation_events
                ADD CONSTRAINT stone_revaluation_events_old_count_positive
                CHECK (old_count >= 1)
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE stone_revaluation_events
                ADD CONSTRAINT stone_revaluation_events_new_count_positive
                CHECK (new_count >= 1)
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE stone_revaluation_events
                ADD CONSTRAINT stone_revaluation_events_old_total_consistent
                CHECK (ROUND((old_unit_value * old_count)::numeric, 2) = ROUND(old_total_value::numeric, 2))
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE stone_revaluation_events
                ADD CONSTRAINT stone_revaluation_events_new_total_consistent
                CHECK (ROUND((new_unit_value * new_count)::numeric, 2) = ROUND(new_total_value::numeric, 2))
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE stone_revaluation_events
                ADD CONSTRAINT stone_revaluation_events_delta_consistent
                CHECK (ROUND((new_total_value - old_total_value)::numeric, 2) = ROUND(delta_total_value::numeric, 2))
            SQL);

            // reason must not be empty
            DB::statement(<<<'SQL'
                ALTER TABLE stone_revaluation_events
                ADD CONSTRAINT stone_revaluation_events_reason_required
                CHECK (LENGTH(TRIM(reason)) > 0)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stone_revaluation_events');
    }
};

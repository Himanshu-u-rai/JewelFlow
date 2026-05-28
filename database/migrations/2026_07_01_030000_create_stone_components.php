<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A — Migration 3/N — Structured stone component primitive.
 *
 * Replaces the opaque `invoice_items.stone_amount` rupee column with
 * a structured per-stone record. Each component carries:
 *   - identity:    shop_id, item_id / invoice_item_id / return_line_item_id
 *   - structure:   stone_type, carat_weight, count
 *   - valuation:   unit_value, total_value (manual)
 *   - audit:       notes, migrated_from_legacy
 *
 * Constitutional rules:
 *   - Article XIV — stone values are manual valuations. No automated
 *     process (RepriceRetailerInventoryJob, FetchLiveMetalRatesJob, any
 *     rate-update flow) may write to this table.
 *   - Article I — once linked to a finalized invoice_item or settled
 *     return_line_item, the row becomes immutable (snapshot doctrine).
 *     Trigger installed in the next migration enforces this.
 *
 * Coexistence with legacy stone_amount:
 *   - invoice_items.stone_amount and items.stone_charges stay forever
 *   - This table is a structured MIRROR, not a replacement
 *   - During Phase 2A backfill, every legacy stone_amount > 0 generates
 *     one stone_components row with stone_type='other' + migrated_from_legacy=true
 *   - Reports / refund / valuation flows continue reading legacy columns
 *     for legacy invoices; new transactions write to BOTH legacy and new
 *     surfaces (writes-shadow during Phase 2A; Phase 2B+ may cut readers
 *     over once parity is proven)
 *
 * SUM invariant (verified at invoice finalization in service layer):
 *   For any invoice_item with linked stone_components rows:
 *     SUM(stone_components.total_value) WHERE invoice_item_id = X
 *     ==
 *     invoice_items.stone_amount WHERE id = X
 *   Violation is a service-layer assertion error; the DB does not
 *   enforce this (legacy invoices with no stone_components rows must
 *   continue to work).
 *
 * CHECK constraints:
 *   - At least one of (item_id, invoice_item_id, return_line_item_id) must
 *     be set (a stone must belong to SOMETHING)
 *   - total_value = ROUND(unit_value * count, 2)
 *   - count >= 1
 *   - carat_weight >= 0 (nullable for unknown legacy)
 *   - unit_value >= 0
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stone_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // Parent FKs — at least one must be set (CHECK constraint below).
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->foreignId('return_line_item_id')->nullable()->constrained('return_line_items')->nullOnDelete();

            // Structure.
            $table->string('stone_type', 40)->index();
            $table->decimal('carat_weight', 8, 3)->nullable();
            $table->integer('count')->default(1);

            // Valuation (manual — Article XIV).
            $table->decimal('unit_value', 12, 2)->default(0);
            $table->decimal('total_value', 12, 2)->default(0);

            // Audit metadata.
            $table->text('notes')->nullable();
            $table->boolean('migrated_from_legacy')->default(false);

            $table->timestamps();

            $table->index(['shop_id', 'item_id'], 'stone_components_item_idx');
            $table->index(['shop_id', 'invoice_item_id'], 'stone_components_invoice_idx');
            $table->index(['shop_id', 'return_line_item_id'], 'stone_components_return_idx');
        });

        // CHECK constraints. Pgsql-only — sqlite test env tolerates skip.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE stone_components
                ADD CONSTRAINT stone_components_parent_required
                CHECK (
                    item_id IS NOT NULL
                    OR invoice_item_id IS NOT NULL
                    OR return_line_item_id IS NOT NULL
                )
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE stone_components
                ADD CONSTRAINT stone_components_count_positive
                CHECK (count >= 1)
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE stone_components
                ADD CONSTRAINT stone_components_carat_non_negative
                CHECK (carat_weight IS NULL OR carat_weight >= 0)
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE stone_components
                ADD CONSTRAINT stone_components_unit_value_non_negative
                CHECK (unit_value >= 0)
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE stone_components
                ADD CONSTRAINT stone_components_total_equals_unit_times_count
                CHECK (
                    ROUND((unit_value * count)::numeric, 2)
                    = ROUND(total_value::numeric, 2)
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stone_components');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical Sales — Batch 3 foundation (1/3): the columns a full historical
 * bill reconstruction needs on a LINE, per HISTORICAL-BATCH-3-UX-CONTRACT-V2
 * §11. Batch 1/2 gave a line a total; this gives it the ingredients that total
 * is built from, so a bill can be edited field-by-field instead of typed as one
 * opaque number.
 *
 * BILLABLE WEIGHT (§4). No live surface has ever had this decision — POS and
 * QuickBill both silently bill on `net_metal_weight`. A historical bill
 * predates that assumption and may have billed on gross weight, net weight, or
 * a figure that matches neither (a shop's own rounding/estimation habits from
 * years ago). `billable_weight_basis` therefore has NO DEFAULT: the operator
 * must pick `gross`, `net` or `manual` before a metal value can be suggested.
 * Leaving it NULL is a valid, common state (header-only / not-yet-decided
 * documents already exist) and every read path must treat NULL as "undecided",
 * never silently fall back to net weight.
 *
 * NEW CHARGE COLUMNS. hallmark/rhodium/other did not exist on this table at
 * all (only `stone_weight` did); they are genuinely new, not reused.
 *
 * WASTAGE. Two live wastage formulas exist and disagree (PricingEngine's flat
 * fine-weight×rate charge added after tax vs QuickBill's percent-of-metal-value
 * charge added before tax). This module does not pick a winner: `wastage_basis`
 * is `percent` or `flat`, defaults to NULL (never auto-applies a shop's current
 * wastage_recovery_percent setting), and the pre-tax placement in the §5
 * formula graph is a structural choice, not a re-litigation of which live
 * formula is "correct".
 *
 * calculation_state (jsonb). One column carries the auto/manual tracking for
 * every DERIVED value on this line (metal_value, making_amount, wastage_amount,
 * stone_value, line_discount_amount, line_taxable, line_total) — see
 * HistoricalCalculationStateService. It is intentionally NOT a set of
 * "*_amount" + "*_is_manual" column pairs: every one of those pairs would need
 * its own trigger allow-list entry and its own immutability-trait wiring, for
 * a value that is read as a whole by the calculation service and never
 * queried column-by-column in SQL. Reusing the existing jsonb-snapshot
 * convention (customer_snapshot, tax_snapshot, item_snapshot) keeps this a
 * one-column addition instead of fourteen.
 *
 * IMMUTABILITY. None of these columns are added to the Postgres trigger's
 * `allowed_cols` array (2026_09_15_000200) or to
 * `HistoricalSalesLine::publishedUpdatableColumns()`. A published line is
 * exactly as frozen as it was before this migration — every new column is
 * simply one more column the trigger already rejects by omission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historical_sales_lines', function (Blueprint $table): void {
            // Canonical MetalRegistry key (e.g. "gold", "platinum"), distinct
            // from the free-text `metal_snapshot` display label — this is what
            // the calculation service actually looks up fine-weight math with.
            $table->string('line_metal_type')->nullable()->after('metal_snapshot');

            $table->string('billable_weight_basis')->nullable()->after('stone_weight');
            $table->decimal('billable_weight', 12, 3)->nullable()->after('billable_weight_basis');

            $table->decimal('hallmark_charge', 18, 2)->nullable()->after('making_value_original');
            $table->decimal('rhodium_charge', 18, 2)->nullable()->after('hallmark_charge');
            $table->decimal('other_charge', 18, 2)->nullable()->after('rhodium_charge');

            $table->string('wastage_basis')->nullable()->after('other_charge');
            $table->decimal('wastage_value', 18, 2)->nullable()->after('wastage_basis');

            $table->string('line_discount_type')->nullable()->after('wastage_value');
            $table->decimal('line_discount_value', 18, 2)->nullable()->after('line_discount_type');

            $table->jsonb('calculation_state')->default(DB::raw("'{}'::jsonb"))->after('raw_payload');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_lines
            ADD CONSTRAINT historical_lines_billable_weight_basis_check
            CHECK (billable_weight_basis IS NULL OR billable_weight_basis IN ('gross', 'net', 'manual'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_lines
            ADD CONSTRAINT historical_lines_wastage_basis_check
            CHECK (wastage_basis IS NULL OR wastage_basis IN ('percent', 'flat'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_lines
            ADD CONSTRAINT historical_lines_discount_type_check
            CHECK (line_discount_type IS NULL OR line_discount_type IN ('fixed', 'percent'))
        SQL);

        // Widen the existing non-negative backstop rather than add five more
        // single-column CHECKs — same reasoning as the original constraint.
        DB::statement('ALTER TABLE historical_sales_lines DROP CONSTRAINT historical_lines_non_negative_check');
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_lines
            ADD CONSTRAINT historical_lines_non_negative_check
            CHECK (
                line_total >= 0
                AND (quantity IS NULL OR quantity >= 0)
                AND (gross_weight IS NULL OR gross_weight >= 0)
                AND (net_weight IS NULL OR net_weight >= 0)
                AND (stone_weight IS NULL OR stone_weight >= 0)
                AND (making_amount IS NULL OR making_amount >= 0)
                AND (billable_weight IS NULL OR billable_weight >= 0)
                AND (hallmark_charge IS NULL OR hallmark_charge >= 0)
                AND (rhodium_charge IS NULL OR rhodium_charge >= 0)
                AND (other_charge IS NULL OR other_charge >= 0)
                AND (wastage_value IS NULL OR wastage_value >= 0)
                AND (line_discount_value IS NULL OR line_discount_value >= 0)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE historical_sales_lines DROP CONSTRAINT historical_lines_non_negative_check');
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_lines
            ADD CONSTRAINT historical_lines_non_negative_check
            CHECK (
                line_total >= 0
                AND (quantity IS NULL OR quantity >= 0)
                AND (gross_weight IS NULL OR gross_weight >= 0)
                AND (net_weight IS NULL OR net_weight >= 0)
                AND (stone_weight IS NULL OR stone_weight >= 0)
                AND (making_amount IS NULL OR making_amount >= 0)
            )
        SQL);

        DB::statement('ALTER TABLE historical_sales_lines DROP CONSTRAINT IF EXISTS historical_lines_discount_type_check');
        DB::statement('ALTER TABLE historical_sales_lines DROP CONSTRAINT IF EXISTS historical_lines_wastage_basis_check');
        DB::statement('ALTER TABLE historical_sales_lines DROP CONSTRAINT IF EXISTS historical_lines_billable_weight_basis_check');

        Schema::table('historical_sales_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'line_metal_type',
                'billable_weight_basis',
                'billable_weight',
                'hallmark_charge',
                'rhodium_charge',
                'other_charge',
                'wastage_basis',
                'wastage_value',
                'line_discount_type',
                'line_discount_value',
                'calculation_state',
            ]);
        });
    }
};

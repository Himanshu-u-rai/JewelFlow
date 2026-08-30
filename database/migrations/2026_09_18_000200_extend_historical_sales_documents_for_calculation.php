<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical Sales — Batch 3 foundation (2/3): bill-level calculation columns,
 * per HISTORICAL-BATCH-3-UX-CONTRACT-V2 §11.
 *
 * ADVANCE / CREDIT (owner-locked). V1 of this module's requirements draft
 * proposed a negative `outstanding_amount_snapshot` for overpayment; that is
 * physically impossible — `historical_docs_non_negative_check` (Batch 1)
 * already requires `outstanding_amount_snapshot >= 0`. The corrected, locked
 * formula is:
 *
 *   outstanding_suggestion = max(grand_total - paid_total, 0)
 *   advance_credit_amount  = max(paid_total - grand_total, 0)
 *
 * `advance_credit_amount` is a RECORD-ONLY field: "Historical excess payment /
 * advance". It never creates live customer/store credit, never touches
 * StoreCreditMovement, and is not consulted by any live receivables/wallet
 * balance. It exists purely so a bill that was genuinely overpaid decades ago
 * can say so without corrupting the non-negative outstanding invariant.
 *
 * SETTLEMENT RECONCILIATION. Batch 2 (2026_09_16_000100) already relaxed the
 * original exact-equality settlement CHECK to a ±₹1 tolerance for the same
 * reason real ledgers are used here: two independently-rounded halves can be
 * off by a paisa or two. This migration extends that CHECK from two terms to
 * the locked three-term identity:
 *
 *   paid_total + outstanding = grand_total + advance_credit  (±₹1)
 *
 * which collapses back to the Batch 2 formula whenever advance_credit is 0/NULL
 * (the overwhelmingly common case), so no previously-valid row is invalidated.
 * `down()` restores the exact Batch 2 two-term expression, byte for byte.
 *
 * GST SPLIT / OFFER SNAPSHOT / BILL DISCOUNT are all genuinely new — no real
 * interstate CGST/SGST/IGST logic and no bill-level discount exist anywhere
 * else in the codebase (Agent E), so there is nothing to reuse here beyond the
 * operator-entered-percentage convention QuickBillService already uses.
 *
 * IMMUTABILITY. None of these columns are added to the documents trigger's
 * `allowed_cols` (2026_09_15_000200) or to
 * `HistoricalSalesDocument::publishedUpdatableColumns()` — publishing freezes
 * them exactly like every existing money column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historical_sales_documents', function (Blueprint $table): void {
            $table->string('bill_discount_type')->nullable()->after('discount_snapshot');
            $table->decimal('bill_discount_value', 18, 2)->nullable()->after('bill_discount_type');

            $table->string('tax_split_type')->nullable()->after('tax_completeness');
            $table->decimal('cgst_amount', 18, 2)->nullable()->after('tax_split_type');
            $table->decimal('sgst_amount', 18, 2)->nullable()->after('cgst_amount');
            $table->decimal('igst_amount', 18, 2)->nullable()->after('sgst_amount');
            $table->decimal('cess_amount', 18, 2)->nullable()->after('igst_amount');

            $table->string('offer_label_snapshot')->nullable()->after('cess_amount');
            $table->decimal('offer_discount_snapshot', 18, 2)->nullable()->after('offer_label_snapshot');

            // Never negative outstanding (Batch 1 CHECK). Overpayment lives here
            // instead — see class docblock.
            $table->decimal('advance_credit_amount', 18, 2)->nullable()->after('outstanding_amount_snapshot');

            $table->jsonb('calculation_state')->default(DB::raw("'{}'::jsonb"))->after('raw_payload');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_docs_bill_discount_type_check
            CHECK (bill_discount_type IS NULL OR bill_discount_type IN ('fixed', 'percent'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_docs_tax_split_type_check
            CHECK (tax_split_type IS NULL OR tax_split_type IN ('cgst_sgst', 'igst'))
        SQL);

        // Widen the existing non-negative backstop.
        DB::statement('ALTER TABLE historical_sales_documents DROP CONSTRAINT historical_docs_non_negative_check');
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_docs_non_negative_check CHECK (
                grand_total >= 0
                AND (taxable_amount IS NULL OR taxable_amount >= 0)
                AND (paid_amount_snapshot IS NULL OR paid_amount_snapshot >= 0)
                AND (outstanding_amount_snapshot IS NULL OR outstanding_amount_snapshot >= 0)
                AND (metal_value IS NULL OR metal_value >= 0)
                AND (stone_value IS NULL OR stone_value >= 0)
                AND (making_amount IS NULL OR making_amount >= 0)
                AND (bill_discount_value IS NULL OR bill_discount_value >= 0)
                AND (cgst_amount IS NULL OR cgst_amount >= 0)
                AND (sgst_amount IS NULL OR sgst_amount >= 0)
                AND (igst_amount IS NULL OR igst_amount >= 0)
                AND (cess_amount IS NULL OR cess_amount >= 0)
                AND (offer_discount_snapshot IS NULL OR offer_discount_snapshot >= 0)
                AND (advance_credit_amount IS NULL OR advance_credit_amount >= 0)
            )
        SQL);

        // Locked three-term settlement identity. See class docblock.
        DB::statement('ALTER TABLE historical_sales_documents DROP CONSTRAINT historical_docs_settlement_reconciliation_check');
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_docs_settlement_reconciliation_check CHECK (
                paid_amount_snapshot IS NULL
                OR outstanding_amount_snapshot IS NULL
                OR ABS(
                    ROUND(paid_amount_snapshot + outstanding_amount_snapshot, 2)
                    - ROUND(grand_total + COALESCE(advance_credit_amount, 0), 2)
                ) <= 1.00
            )
        SQL);
    }

    public function down(): void
    {
        // Restore the Batch 2 (2026_09_16_000100) two-term expression exactly.
        DB::statement('ALTER TABLE historical_sales_documents DROP CONSTRAINT historical_docs_settlement_reconciliation_check');
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_docs_settlement_reconciliation_check CHECK (
                paid_amount_snapshot IS NULL
                OR outstanding_amount_snapshot IS NULL
                OR ABS(ROUND(paid_amount_snapshot + outstanding_amount_snapshot, 2) - ROUND(grand_total, 2)) <= 1.00
            )
        SQL);

        DB::statement('ALTER TABLE historical_sales_documents DROP CONSTRAINT historical_docs_non_negative_check');
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_docs_non_negative_check CHECK (
                grand_total >= 0
                AND (taxable_amount IS NULL OR taxable_amount >= 0)
                AND (paid_amount_snapshot IS NULL OR paid_amount_snapshot >= 0)
                AND (outstanding_amount_snapshot IS NULL OR outstanding_amount_snapshot >= 0)
                AND (metal_value IS NULL OR metal_value >= 0)
                AND (stone_value IS NULL OR stone_value >= 0)
                AND (making_amount IS NULL OR making_amount >= 0)
            )
        SQL);

        DB::statement('ALTER TABLE historical_sales_documents DROP CONSTRAINT IF EXISTS historical_docs_tax_split_type_check');
        DB::statement('ALTER TABLE historical_sales_documents DROP CONSTRAINT IF EXISTS historical_docs_bill_discount_type_check');

        Schema::table('historical_sales_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'bill_discount_type',
                'bill_discount_value',
                'tax_split_type',
                'cgst_amount',
                'sgst_amount',
                'igst_amount',
                'cess_amount',
                'offer_label_snapshot',
                'offer_discount_snapshot',
                'advance_credit_amount',
                'calculation_state',
            ]);
        });
    }
};

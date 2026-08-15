<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical Sales — Batch 2: the columns the import workflow needs.
 *
 * Batch 1 built the record. This adds only what parsing, mapping, preview and
 * publication cannot work without. `historical_sales_documents` and
 * `historical_sales_lines` already carry every Phase 6 field, so the document
 * shape is untouched apart from the cutover-acknowledgement audit pair.
 *
 * Nothing here touches invoices, invoice_items, quick_bills, shop_counters,
 * ledgers, stock or payments.
 */
return new class extends Migration
{
    /**
     * The eight calculation bases a jeweller's making/labour column can actually
     * mean. Batch 1 shipped five and collapsed "a flat amount for the whole bill"
     * and "a flat amount for this line" into one `flat` value — those reconcile
     * completely differently (once vs per line), so guessing between them is the
     * silent-total-corruption this module exists to prevent. `flat` is dropped
     * rather than kept as a synonym: no row has ever been written with it, and
     * leaving an ambiguous value in the domain invites its use.
     */
    private const MAKING_BASES = [
        'fixed_invoice',
        'fixed_line',
        'per_item',
        'per_gram',
        'percent',
        'included',
        'informational',
        'unknown',
    ];

    public function up(): void
    {
        $this->extendProfiles();
        $this->extendBatches();
        $this->extendDocuments();
        $this->widenMakingBasis();
        $this->relaxSettlementReconciliation();
    }

    public function down(): void
    {
        $this->dropConstraint('historical_sales_documents', 'historical_docs_settlement_reconciliation_check');
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_docs_settlement_reconciliation_check CHECK (
                paid_amount_snapshot IS NULL
                OR outstanding_amount_snapshot IS NULL
                OR ROUND(paid_amount_snapshot + outstanding_amount_snapshot, 2) = ROUND(grand_total, 2)
            )
        SQL);

        $legacy = "('per_gram', 'percent', 'flat', 'included', 'unknown')";
        $this->dropConstraint('historical_sales_documents', 'historical_docs_making_basis_check');
        DB::statement(
            'ALTER TABLE historical_sales_documents ADD CONSTRAINT historical_docs_making_basis_check '
            . "CHECK (making_basis IS NULL OR making_basis IN {$legacy})"
        );
        $this->dropConstraint('historical_sales_lines', 'historical_lines_making_basis_check');
        DB::statement(
            'ALTER TABLE historical_sales_lines ADD CONSTRAINT historical_lines_making_basis_check '
            . "CHECK (making_basis IS NULL OR making_basis IN {$legacy})"
        );

        $this->dropConstraint('historical_sales_documents', 'historical_docs_cutover_actor_check');
        Schema::table('historical_sales_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cutover_warning_acknowledged_by');
            $table->dropColumn('cutover_warning_acknowledged_at');
        });

        $this->dropConstraint('historical_import_batches', 'historical_import_batches_warning_ack_check');
        Schema::table('historical_import_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warnings_acknowledged_by');
            $table->dropColumn([
                'source_file_disk',
                'source_file_path',
                'layout_type',
                'date_format',
                'preview_summary',
                'preview_generated_at',
                'blocking_count',
                'warning_count',
                'warnings_acknowledged_at',
                'duplicate_resolutions',
            ]);
        });

        $this->dropConstraint('historical_import_profiles', 'historical_import_profiles_tax_mode_check');
        $this->dropConstraint('historical_import_profiles', 'historical_import_profiles_separator_check');
        Schema::table('historical_import_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'header_row',
                'decimal_separator',
                'thousands_separator',
                'tax_mode',
                'sheets',
                'column_decisions',
            ]);
        });
    }

    /**
     * Everything the operator confirmed on the mapping screen, so re-importing
     * next year's file from the same accounting package is a one-click reuse and
     * never a second round of guessing.
     */
    private function extendProfiles(): void
    {
        Schema::table('historical_import_profiles', function (Blueprint $table): void {
            // 1-based, matching what the operator sees in Excel's row gutter.
            $table->unsignedSmallInteger('header_row')->default(1);

            // Explicit, never sniffed. `125.000,00` is one hundred twenty-five
            // thousand in Germany and one hundred twenty-five in India; a parser
            // that guesses is a parser that moves a decimal place on some rows and
            // not others, which is undetectable after the fact.
            $table->string('decimal_separator', 1)->default('.');
            // Nullable: plenty of exports group nothing at all.
            $table->string('thousands_separator', 1)->nullable()->default(',');

            // Whether the source's line/total figures already contain tax.
            $table->string('tax_mode')->default('unknown');

            // Layout A/B: {"data": "Sheet1"}.
            // Layout C: {"header": "Invoices", "detail": "Items"}.
            $table->jsonb('sheets')->nullable();

            // Every source column the operator did NOT map, and what they decided
            // about it: ignored / informational. A monetary column that is simply
            // absent from `mapping` is indistinguishable from one the operator
            // never saw, and silently dropping money is the failure mode here.
            $table->jsonb('column_decisions')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_profiles
            ADD CONSTRAINT historical_import_profiles_tax_mode_check
            CHECK (tax_mode IN ('inclusive', 'exclusive', 'unknown', 'not_applicable'))
        SQL);

        // Same character for both means every parse is ambiguous.
        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_profiles
            ADD CONSTRAINT historical_import_profiles_separator_check
            CHECK (thousands_separator IS NULL OR thousands_separator <> decimal_separator)
        SQL);
    }

    private function extendBatches(): void
    {
        Schema::table('historical_import_batches', function (Blueprint $table): void {
            // Private disk + generated path. The uploaded workbook is evidence for
            // the life of the batch: preview is re-derivable, and a disputed figure
            // has to be checkable against the file the operator actually sent.
            $table->string('source_file_disk')->nullable();
            $table->string('source_file_path')->nullable();

            // Snapshots of the profile at parse time. A profile is editable and
            // reusable; the batch must remember which layout and date format
            // actually produced ITS numbers, or a later profile edit silently
            // rewrites the meaning of an already-published import.
            $table->string('layout_type')->nullable();
            $table->string('date_format')->nullable();

            $table->jsonb('preview_summary')->nullable();
            $table->timestamp('preview_generated_at')->nullable();

            $table->unsignedInteger('blocking_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);

            $table->foreignId('warnings_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('warnings_acknowledged_at')->nullable();

            // {grouping_key: {action, target_document_id, series, reason, actor, at}}
            $table->jsonb('duplicate_resolutions')->nullable();
        });

        // An acknowledgement without an actor is not an acknowledgement.
        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_batches
            ADD CONSTRAINT historical_import_batches_warning_ack_check
            CHECK (
                (warnings_acknowledged_at IS NULL AND warnings_acknowledged_by IS NULL)
                OR (warnings_acknowledged_at IS NOT NULL AND warnings_acknowledged_by IS NOT NULL)
            )
        SQL);
    }

    /**
     * Batch 1 recorded THAT a cutover warning was acknowledged and WHY. Phase 4
     * also requires WHO and WHEN — an acknowledgement nobody owns is a rubber
     * stamp. Both columns are written while the document is still a draft, so the
     * published-immutability trigger's allow-list needs no change.
     */
    private function extendDocuments(): void
    {
        Schema::table('historical_sales_documents', function (Blueprint $table): void {
            $table->foreignId('cutover_warning_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cutover_warning_acknowledged_at')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_docs_cutover_actor_check
            CHECK (
                cutover_warning_acknowledged = false
                OR (cutover_warning_acknowledged_by IS NOT NULL AND cutover_warning_acknowledged_at IS NOT NULL)
            )
        SQL);
    }

    private function widenMakingBasis(): void
    {
        $list = "'" . implode("', '", self::MAKING_BASES) . "'";

        $this->dropConstraint('historical_sales_documents', 'historical_docs_making_basis_check');
        DB::statement(
            'ALTER TABLE historical_sales_documents ADD CONSTRAINT historical_docs_making_basis_check '
            . "CHECK (making_basis IS NULL OR making_basis IN ({$list}))"
        );

        $this->dropConstraint('historical_sales_lines', 'historical_lines_making_basis_check');
        DB::statement(
            'ALTER TABLE historical_sales_lines ADD CONSTRAINT historical_lines_making_basis_check '
            . "CHECK (making_basis IS NULL OR making_basis IN ({$list}))"
        );
    }

    /**
     * Batch 1 demanded paid + outstanding = grand_total EXACTLY. Real ledgers
     * round the settlement halves independently, so a genuine bill can be off by
     * fifty paise — and the exact rule turns that into a hard insert failure the
     * operator cannot fix without falsifying one of the two printed figures.
     *
     * The rule becomes the same ±₹1 tolerance the rest of the module uses. That is
     * a relaxation of a DATABASE backstop, not of the business rule: any non-zero
     * difference is still surfaced as a warning the operator must acknowledge, and
     * anything past ₹1 is still blocked — now by the application, where the
     * operator can be told which figure to correct instead of seeing SQLSTATE.
     */
    private function relaxSettlementReconciliation(): void
    {
        $this->dropConstraint('historical_sales_documents', 'historical_docs_settlement_reconciliation_check');

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_docs_settlement_reconciliation_check CHECK (
                paid_amount_snapshot IS NULL
                OR outstanding_amount_snapshot IS NULL
                OR ABS(ROUND(paid_amount_snapshot + outstanding_amount_snapshot, 2) - ROUND(grand_total, 2)) <= 1.00
            )
        SQL);
    }

    private function dropConstraint(string $table, string $name): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
    }
};

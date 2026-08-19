<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `opening_balance_overlap` (existing boolean) says only THAT a document's date
 * falls on/before a linked customer's receivable opening-balance cutoff. It
 * cannot say what the operator decided about it. These three columns record
 * that decision — metadata only, never a balance/ledger write:
 *
 *   opening_balance_resolution    — 'included_in_opening_balance' or
 *                                    'separate_from_opening_balance'. No third
 *                                    "not applicable" escape hatch: a HIGH
 *                                    overlap must be looked at, not waved off.
 *   opening_balance_resolved_by   — who decided (nullable FK, matches the
 *                                    cutover_warning_acknowledged_by pattern).
 *   opening_balance_resolved_at   — when.
 *
 * All three are draft-only writes at the app layer (HistoricalDocumentLifecycle
 * Service). No trigger change is needed to freeze them post-publish: the
 * existing historical_sales_documents_guard() trigger already restricts every
 * non-draft row to its allowed_cols allow-list, and these three columns are
 * deliberately never added to it — so Postgres itself rejects a post-publish
 * write even if the app-layer guard were ever bypassed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historical_sales_documents', function (Blueprint $table): void {
            $table->string('opening_balance_resolution')->nullable();
            $table->foreignId('opening_balance_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opening_balance_resolved_at')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_sales_documents_opening_balance_resolution_check
            CHECK (
                opening_balance_resolution IS NULL
                OR opening_balance_resolution IN ('included_in_opening_balance', 'separate_from_opening_balance')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_sales_documents_opening_balance_resolved_pair_check
            CHECK (
                opening_balance_resolution IS NULL
                OR (opening_balance_resolved_by IS NOT NULL AND opening_balance_resolved_at IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE historical_sales_documents DROP CONSTRAINT IF EXISTS historical_sales_documents_opening_balance_resolved_pair_check');
        DB::statement('ALTER TABLE historical_sales_documents DROP CONSTRAINT IF EXISTS historical_sales_documents_opening_balance_resolution_check');

        Schema::table('historical_sales_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('opening_balance_resolved_by');
            $table->dropColumn(['opening_balance_resolution', 'opening_balance_resolved_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `opening_balance_resolution` recorded the operator's decision, but nothing
 * stopped it from being set on a document that has no overlap at all (severity
 * NONE) — the value-list and all-or-nothing CHECKs added in
 * 2026_09_17_000100 both accept that shape. This closes that gap at the
 * database, matching the service-layer guard added in
 * HistoricalDocumentLifecycleService::resolveOpeningBalance(): a resolution
 * may only exist when `opening_balance_overlap` is true.
 *
 * A separate migration rather than editing 2026_09_17_000100 directly, so the
 * original migration's history stays intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_documents
            ADD CONSTRAINT historical_sales_documents_opening_balance_resolution_requires_overlap_check
            CHECK (
                opening_balance_resolution IS NULL
                OR opening_balance_overlap = true
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE historical_sales_documents DROP CONSTRAINT IF EXISTS historical_sales_documents_opening_balance_resolution_requires_overlap_check');
    }
};

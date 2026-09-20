<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit finding S3-03 — record which disk a stock purchase invoice lives on.
 *
 * At baseline 018b3d8 the disk was implicit: StockPurchaseController::store():127
 * and update():354 both hard-coded 'public', and destroy() hard-coded it again on
 * delete. Moving new uploads to the private disk therefore needs a per-row record,
 * or every attachment already written becomes unreachable.
 *
 * Design notes — deliberately identical to the karigar migration
 * (2026_09_15_120000), because the failure mode is identical:
 *
 *  - The column is NULLABLE, not NOT NULL DEFAULT 'public'. A default is exactly
 *    how the KYC finding (S3-01c) arose: kyc_documents.file_disk is NOT NULL
 *    DEFAULT 'public', so a writer that forgets the column silently gets a
 *    web-served disk and no error. A default cannot fail loudly; a constraint can.
 *
 *  - Two CHECK constraints, expressing two different invariants:
 *      * both-or-neither — a row either has NO attachment (both columns NULL) or
 *        has one whose disk is explicitly recorded (both NOT NULL). There is no
 *        third state for a future writer to fall into.
 *      * allowed disks — 'public' or 'local' only. Without this, a typo'd or
 *        unconfigured disk name is accepted at write time and only surfaces as a
 *        runtime error on read, by which point the upload is long gone.
 *
 *  - The `invoice_image_disk IS NOT NULL` term in the second branch is load-
 *    bearing and must not be simplified away as redundant with the IN list. A
 *    CHECK constraint rejects only an explicit FALSE; it PASSES when the
 *    expression evaluates to NULL. `NULL IN ('public','local')` is NULL, not
 *    FALSE, so without the explicit IS NOT NULL the both-or-neither invariant
 *    silently admits a path with no disk. Caught by
 *    PurchaseInvoiceAttachmentTest P-09, which failed against the first draft of
 *    this migration.
 *
 *  - Existing rows that already hold a path are backfilled to 'public' because
 *    that is where those bytes actually are. This is a truthful relabel of the
 *    status quo, NOT a remediation: the files stay on the public tree until the
 *    separately-approved relocation procedure runs. Backfilling to 'local' would
 *    strand every existing attachment.
 *
 * No trigger is dropped, disabled or altered. stock_purchases carries no
 * constitutionally-protected finalized guard (Art. IX.A lists none for this
 * table), and the backfill touches only the new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_purchases')) {
            return;
        }

        if (! Schema::hasColumn('stock_purchases', 'invoice_image_disk')) {
            Schema::table('stock_purchases', function (Blueprint $table) {
                $table->string('invoice_image_disk', 20)->nullable()->after('invoice_image');
            });
        }

        // Existing attachments are physically on the public disk. Record the truth.
        DB::table('stock_purchases')
            ->whereNotNull('invoice_image')
            ->whereNull('invoice_image_disk')
            ->update(['invoice_image_disk' => 'public']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_purchases')) {
            return;
        }

        DB::statement('ALTER TABLE stock_purchases DROP CONSTRAINT IF EXISTS stock_purchases_invoice_image_disk_check');

        if (Schema::hasColumn('stock_purchases', 'invoice_image_disk')) {
            Schema::table('stock_purchases', function (Blueprint $table) {
                $table->dropColumn('invoice_image_disk');
            });
        }
    }
};

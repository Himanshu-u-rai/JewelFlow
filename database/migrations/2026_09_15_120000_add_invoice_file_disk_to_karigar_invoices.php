<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit finding S3-02 — record which disk a karigar invoice attachment lives on.
 *
 * At baseline 018b3d8 the disk was implicit: KarigarInvoiceService::create():48
 * and update():114 both hard-coded 'public', and KarigarInvoiceController::destroy()
 * hard-coded it again on delete. Moving new uploads to the private disk therefore
 * needs a per-row record, or existing attachments become unreachable.
 *
 * EXPAND PHASE ONLY — see the release-order note at the foot of this block.
 *
 * Design notes:
 *  - The column is NULLABLE, not NOT NULL DEFAULT 'public'. A default is exactly
 *    how the KYC finding (S3-01c) arose: kyc_documents.file_disk is NOT NULL
 *    DEFAULT 'public', so any writer that forgets the column silently gets a
 *    web-served disk and no error. A default cannot fail loudly; a constraint can.
 *  - The both-or-neither CHECK that expresses the real invariant NO LONGER LIVES
 *    HERE. It moved to the contract-phase migration 2026_09_20_130000, because
 *    applying it while baseline 018b3d8 is still serving turns every karigar
 *    attachment upload into a database error: KarigarInvoiceService:49,114 write
 *    invoice_file_path and never the disk. Proven by
 *    DiskColumnReleaseOrderTest T-05.
 *  - Existing rows that already hold a path are backfilled to 'public' because
 *    that is where those bytes actually are. This is a truthful relabel of the
 *    status quo, NOT a remediation: the files stay on the public tree until the
 *    separately-approved relocation procedure runs.
 *
 * The backfill UPDATE fires karigar_invoices_finalized_guard_trigger
 * (Constitution Art. IX.A #15, BEFORE UPDATE OR DELETE). That guard freezes only
 * total_after_tax, total_before_tax, total_tax, karigar_invoice_number,
 * karigar_invoice_date and karigar_id once payment_status leaves 'unpaid'.
 * invoice_file_disk is not among them, so finalized invoices accept this write.
 * The trigger is not dropped, disabled or altered.
 *
 * RELEASE ORDER. What remains here — add a nullable column, backfill it — really
 * is safe to apply while the old code serves, because the old code neither reads
 * nor writes this column and a NULL is a state it already produces. That is what
 * "additive" was supposed to mean; the constraint never qualified.
 *
 * down() still drops the contract constraint before dropping the column. Not
 * because it has to — I assumed it did, and checked instead of asserting.
 * PostgreSQL 16.15 drops a CHECK automatically with any column it references,
 * including a two-column one, so DROP COLUMN alone would have succeeded
 * (verified 2026-09-20 on the local jewelflow_testing database with a scratch
 * table inside a rolled-back transaction). The explicit DROP is kept because it
 * makes the rollback independent of that behaviour and of which phase happens to
 * be applied, and IF EXISTS makes it a no-op in the expand-only window.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('karigar_invoices')) {
            return;
        }

        if (! Schema::hasColumn('karigar_invoices', 'invoice_file_disk')) {
            Schema::table('karigar_invoices', function (Blueprint $table) {
                $table->string('invoice_file_disk', 20)->nullable()->after('invoice_file_path');
            });
        }

        // Existing attachments are physically on the public disk. Record the truth.
        DB::table('karigar_invoices')
            ->whereNotNull('invoice_file_path')
            ->whereNull('invoice_file_disk')
            ->update(['invoice_file_disk' => 'public']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('karigar_invoices')) {
            return;
        }

        DB::statement('ALTER TABLE karigar_invoices DROP CONSTRAINT IF EXISTS karigar_invoices_attachment_disk_check');

        if (Schema::hasColumn('karigar_invoices', 'invoice_file_disk')) {
            Schema::table('karigar_invoices', function (Blueprint $table) {
                $table->dropColumn('invoice_file_disk');
            });
        }
    }
};

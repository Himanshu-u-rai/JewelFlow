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
 * Design notes:
 *  - The column is NULLABLE, not NOT NULL DEFAULT 'public'. A default is exactly
 *    how the KYC finding (S3-01c) arose: kyc_documents.file_disk is NOT NULL
 *    DEFAULT 'public', so any writer that forgets the column silently gets a
 *    web-served disk and no error. A default cannot fail loudly; a constraint can.
 *  - The CHECK constraint expresses the real invariant: a row either has NO
 *    attachment (both columns NULL) or has one whose disk is explicitly recorded
 *    (both NOT NULL). There is no third state for a future writer to fall into.
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

        // Both-or-neither. A path can never again be stored without its disk.
        DB::statement(<<<'SQL'
            ALTER TABLE karigar_invoices
            ADD CONSTRAINT karigar_invoices_attachment_disk_check
            CHECK (
                (invoice_file_path IS NULL AND invoice_file_disk IS NULL)
                OR (invoice_file_path IS NOT NULL AND invoice_file_disk IS NOT NULL)
            )
        SQL);
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

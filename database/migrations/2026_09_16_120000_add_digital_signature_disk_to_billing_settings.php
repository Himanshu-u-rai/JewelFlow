<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit finding S3-04 — record which disk a shop's digital signature lives on.
 *
 * At baseline 018b3d8 the disk was implicit and always 'public':
 * SettingsController::update():519-531 stores via ImageOptimizer with the literal
 * 'public', and both print blades read it back with
 * Storage::disk('public')->url(...). Moving new uploads to the private disk
 * therefore needs a per-row record, or every signature already written becomes
 * unreachable at reprint time.
 *
 * Same shape as the karigar (2026_09_15_120000) and stock purchase
 * (2026_09_15_140000) migrations, for the same reasons: nullable rather than
 * NOT NULL DEFAULT 'public' (a default cannot fail loudly — that is precisely how
 * S3-01c arose), a both-or-neither CHECK so no third state exists, and an
 * explicit `IS NOT NULL` term in the second branch because a CHECK rejects only
 * an explicit FALSE and `NULL IN ('public','local')` evaluates to NULL, not FALSE.
 *
 * WHAT THIS MIGRATION DOES NOT DO
 * A disk column alone does NOT make historical signatures immutable. The bytes can
 * still be overwritten or deleted, and at baseline the print path ignores the
 * render snapshot entirely and reads live settings. Immutability comes from
 * SignatureStore (never deletes) plus InvoiceSignatureRenderer (snapshot-first).
 * This column only makes "which disk" answerable per row so those two can work.
 *
 * Existing rows holding a path are backfilled to 'public' because that is where
 * those bytes physically are. That is a truthful relabel of the status quo, NOT a
 * remediation — the files stay on the public tree until the separately-approved
 * relocation procedure runs. Backfilling to 'local' would strand every existing
 * signature.
 *
 * No trigger is dropped, disabled or altered; shop_billing_settings carries none
 * of the Article IX.A protected triggers, and the backfill touches only the new
 * column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_billing_settings')) {
            return;
        }

        if (! Schema::hasColumn('shop_billing_settings', 'digital_signature_disk')) {
            Schema::table('shop_billing_settings', function (Blueprint $table) {
                $table->string('digital_signature_disk', 20)->nullable()->after('digital_signature_path');
            });
        }

        DB::table('shop_billing_settings')
            ->whereNotNull('digital_signature_path')
            ->whereNull('digital_signature_disk')
            ->update(['digital_signature_disk' => 'public']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('shop_billing_settings')) {
            return;
        }

        DB::statement('ALTER TABLE shop_billing_settings DROP CONSTRAINT IF EXISTS shop_billing_settings_digital_signature_disk_check');

        if (Schema::hasColumn('shop_billing_settings', 'digital_signature_disk')) {
            Schema::table('shop_billing_settings', function (Blueprint $table) {
                $table->dropColumn('digital_signature_disk');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B — Migration 1/4 — Add advanced stone metadata columns.
 *
 * Per the governance doc (docs/runbooks/phase-2b-governance.md), all
 * Phase 2B additions are pure metadata that NEVER affect accounting
 * totals. They are:
 *   - certificate_id          (e.g., "GIA-12345" — the cert serial)
 *   - certificate_authority   (e.g., "GIA", "IGI", "shop")
 *   - grade                   (free-text, e.g., "VVS1", "SI2", "commercial")
 *   - supplier_name           (vendor/source name — audit trail)
 *   - photo_path              (storage path to scan/photo of the stone)
 *
 * Constitutional rules:
 *   - Article XIV (Manual Valuation Boundary): none of these columns
 *     participate in any pricing formula. They are descriptive only.
 *   - Article I (Inviolability): once snapshotted to a finalized
 *     invoice_item, these columns are locked alongside the existing
 *     value columns. Migration 020000 (next) extends the snapshot
 *     guard to enforce this.
 *
 * All columns are nullable so Phase 2A rows continue to function
 * unchanged. Phase 2B is purely additive — droppable as a single
 * PR revert per the removability test in the governance doc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stone_components')) {
            return;
        }

        Schema::table('stone_components', function (Blueprint $table): void {
            if (! Schema::hasColumn('stone_components', 'certificate_id')) {
                $table->string('certificate_id', 60)->nullable()->after('notes')
                    ->comment('Phase 2B — certificate serial number (e.g., GIA-12345). NULL for uncertified stones.');
            }
            if (! Schema::hasColumn('stone_components', 'certificate_authority')) {
                $table->string('certificate_authority', 40)->nullable()->after('certificate_id')
                    ->comment('Phase 2B — issuing authority (GIA, IGI, shop, etc.).');
            }
            if (! Schema::hasColumn('stone_components', 'grade')) {
                $table->string('grade', 40)->nullable()->after('certificate_authority')
                    ->comment('Phase 2B — free-text grade label (VVS1, SI2, commercial, etc.). Descriptive, not pricing.');
            }
            if (! Schema::hasColumn('stone_components', 'supplier_name')) {
                $table->string('supplier_name', 120)->nullable()->after('grade')
                    ->comment('Phase 2B — supplier/vendor name. Audit trail; no FK to keep simple.');
            }
            if (! Schema::hasColumn('stone_components', 'photo_path')) {
                $table->string('photo_path', 255)->nullable()->after('supplier_name')
                    ->comment('Phase 2B — storage path to stone photo/certificate scan.');
            }
        });

        // Optional unique-when-not-null index on (shop_id, certificate_authority, certificate_id)
        // to prevent the same certificate being recorded twice in a shop.
        // Skipped for now — luxury shops sometimes legitimately list the same cert across
        // re-mounting events. Documented as a Phase 2B+ enhancement.
    }

    public function down(): void
    {
        if (! Schema::hasTable('stone_components')) {
            return;
        }
        Schema::table('stone_components', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('stone_components', 'certificate_id')        ? 'certificate_id' : null,
                Schema::hasColumn('stone_components', 'certificate_authority') ? 'certificate_authority' : null,
                Schema::hasColumn('stone_components', 'grade')                 ? 'grade' : null,
                Schema::hasColumn('stone_components', 'supplier_name')         ? 'supplier_name' : null,
                Schema::hasColumn('stone_components', 'photo_path')            ? 'photo_path' : null,
            ]));
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};

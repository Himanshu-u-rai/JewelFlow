<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical Sales — Batch 5 (1/N): evidence attachments (scanned bills/proof)
 * against a historical document.
 *
 * PII/evidence posture mirrors `kyc_documents` exactly: PRIVATE 'local' disk
 * only, streamed through an authenticated shop-scoped route, never a public
 * URL. `KycDocumentService` is reused as-is for the store/delete mechanics —
 * this table only adds the historical-specific parent link.
 *
 * Composite `(historical_sales_document_id, shop_id) -> (id, shop_id)` FK
 * mirrors `historical_sales_payments` (2026_09_18_000300): a plain FK on the
 * child id alone cannot also guarantee the child's `shop_id` matches its
 * parent's, so both columns are carried and jointly constrained.
 *
 * Removal is a SOFT deactivate (`is_active` + `removed_*` columns), not a row
 * delete — unlike payments, evidence must survive even after the parent
 * document is published, so an operator can retract a wrongly-attached scan
 * post-publish without losing the audit trail of what was removed, by whom,
 * and why. The all-or-nothing CHECK below is the DB-level backstop for that
 * invariant; the model's write path is the one that actually populates the
 * three columns together (see `HistoricalSalesDocumentAttachment`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_sales_document_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->unsignedBigInteger('historical_sales_document_id');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('file_path', 500);
            $table->string('file_disk', 20)->default('local');
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('removed_at')->nullable();
            // restrictOnDelete, NOT nullOnDelete: the CHECK below requires a
            // remover on every inactive row, so SET NULL would try to blank a
            // column the CHECK forbids blanking — the hard delete would still
            // fail, but as an unreadable check violation instead of a plain
            // "this user is referenced" FK error. Retaining the attribution IS
            // the requirement; RESTRICT states it directly.
            // This bites only on hard DELETE of a users row. Deactivating a
            // staff account (`is_active = false`) touches nothing here.
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('removed_reason')->nullable();

            $table->timestamps();

            $table->index(['shop_id']);
        });

        DB::statement(
            'CREATE INDEX historical_attachments_document_shop_index '
            .'ON historical_sales_document_attachments (historical_sales_document_id, shop_id)'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_document_attachments
            ADD CONSTRAINT historical_attachments_document_shop_foreign
            FOREIGN KEY (historical_sales_document_id, shop_id)
            REFERENCES historical_sales_documents (id, shop_id) ON DELETE CASCADE
        SQL);

        // Removal metadata is all-or-nothing: a row is either untouched (all
        // three null, is_active true) or fully removed (all three set,
        // is_active false). No half-removed state is representable.
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_document_attachments
            ADD CONSTRAINT historical_attachments_removal_metadata_check
            CHECK (
                (is_active = true AND removed_at IS NULL AND removed_by IS NULL AND removed_reason IS NULL)
                OR
                (is_active = false AND removed_at IS NOT NULL AND removed_by IS NOT NULL AND removed_reason IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_sales_document_attachments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting spine — Phase 0 (REPORT_EXPORT_IMPLEMENTATION_PLAN.md §0.2, frozen §16).
 *
 * Metadata-only audit of every export event. NO exported file is ever stored
 * long-term (frozen §16); `file_disk`/`file_path` point at a TRANSIENT queued
 * artifact that the scheduled sweep deletes after `expires_at`, while this row
 * persists.
 *
 * Immutability ("reuse AuditLog discipline") is enforced at the MODEL layer
 * (App\Models\Reporting\ReportExport): provenance columns are write-once and
 * rows are never deleted by the app, but the queued lifecycle (`status`,
 * `row_count`, file fields, `finished_at`, `error`) is allowed to transition.
 * A blanket DB append-only trigger is intentionally NOT used here — audit_logs
 * already demonstrated that such a trigger fights FK-cascade deletes
 * (2026_05_15_180000); this table is operational metadata, not a
 * CONSTITUTION-protected financial ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Provenance (write-once — frozen §15)
            $table->string('report_key', 64);              // e.g. sales-register, gstr1
            $table->string('report_version', 32);          // ReportDefinition.version
            $table->string('profile', 64)->nullable();     // Summary|Detailed|CA|CA Standard|Raw|Fixed
            $table->string('profile_version', 32)->nullable();
            $table->string('format', 16);                  // pdf|excel|csv|screen
            $table->json('filters')->nullable();           // resolved filter set
            $table->boolean('sensitive_included')->default(false); // frozen §16

            // Lifecycle (mutable — frozen §20 sync/queued)
            $table->string('mode', 16)->default('sync');   // sync|queued
            $table->string('status', 16)->default('done'); // queued|processing|done|failed
            $table->unsignedInteger('row_count')->nullable();
            $table->string('file_disk', 32)->nullable();   // transient artifact only
            $table->string('file_path')->nullable();
            $table->timestamp('expires_at')->nullable();   // 7-day signed-link window
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'report_key']);
            $table->index(['shop_id', 'user_id']);
            $table->index(['status', 'expires_at']); // sweep query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};

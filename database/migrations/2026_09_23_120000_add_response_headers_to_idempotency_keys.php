<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3-09e — headers a replay must carry (ETag, X-Has-Entity-Tag).
 *
 * Additive and nullable, no default and no backfill, so PostgreSQL adds it as
 * a catalogue change without rewriting the table, and baseline code — which
 * never names the column — is unaffected while it serves. A claim completed
 * without it replays as it always did.
 *
 * Order: this migration before the code that writes it; on rollback, the code
 * first. If the column is dropped under the new code, EnsureIdempotency still
 * records status and body and only the headers are lost (simulated in
 * MobileIdempotencyReplayFidelityTest; measured with the column really dropped
 * in the migration rollback rehearsal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->jsonb('response_headers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropColumn('response_headers');
        });
    }
};

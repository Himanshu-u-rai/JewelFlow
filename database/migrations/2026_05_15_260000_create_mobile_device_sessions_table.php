<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the mobile_device_sessions table.
 *
 * Design rationale (fully documented in the architecture plan):
 *
 * - One immutable row per login event (never updateOrCreate).
 *   Shared devices (e.g. owner hands phone to cashier) create separate rows
 *   with correct user attribution. Session chronology is always preserved.
 *
 * - token_id FK with ON DELETE SET NULL: when Sanctum deletes a token for any
 *   reason (login eviction, explicit logout, termination, stale pruning),
 *   token_id cascades to NULL automatically. The session row persists as history.
 *
 * - Partial unique index on (user_id, shop_id) WHERE logged_out_at IS NULL:
 *   DB-level safety net for the single-active-session-per-user invariant.
 *   The application logic marks the prior session ended before inserting the
 *   new one; this index catches any bug that forgets to do so.
 *
 * - ended_reason: distinguishes logout / replaced / revoked / terminated /
 *   stale_pruned at the row level — enables support triage without needing
 *   AuditLog joins for the common case.
 *
 * - last_seen_at: snapshotted from personal_access_tokens.last_used_at just
 *   before the token is deleted. Provides "when was this device last active?"
 *   for historical sessions whose tokens no longer exist.
 *
 * - All device metadata (device_name, platform, etc.) is client-supplied and
 *   used only for display. It has zero influence on auth decisions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_device_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')
                ->constrained('shops')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Client-generated installation UUID (stored in expo-secure-store).
            // Nullable: old app versions that don't send it get NULL.
            // Not unique — multiple login rows for the same device are allowed.
            $table->string('device_uuid', 36)->nullable();

            // Client-supplied display labels (trusted for display only).
            $table->string('device_name', 100)->nullable();
            $table->string('platform', 20)->nullable();       // 'ios' | 'android'
            $table->string('app_version', 20)->nullable();
            $table->string('os_version', 30)->nullable();

            // Server-stamped login IP (reliable, not client-supplied).
            $table->string('ip_address', 45)->nullable();

            // FK to personal_access_tokens — NULL after token is deleted.
            // Using bigint/unsignedBigInteger directly because Sanctum's PAT
            // table uses a bigint PK and foreignId() would look for a 'personal_access_token'
            // table alias that doesn't exist in the schema helper.
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->foreign('token_id')
                ->references('id')
                ->on('personal_access_tokens')
                ->nullOnDelete();

            $table->timestamp('logged_in_at');

            // Snapshotted from personal_access_tokens.last_used_at at session end.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamp('logged_out_at')->nullable();

            // 'logout' | 'replaced' | 'revoked' | 'terminated' | 'stale_pruned'
            $table->string('ended_reason', 20)->nullable();

            $table->timestamps();
        });

        // Fast lookup: list all sessions for a shop or a specific user.
        Schema::table('mobile_device_sessions', function (Blueprint $table) {
            $table->index(['shop_id', 'user_id'], 'mds_shop_user_idx');
        });

        // Fast lookup: full session history for a specific device UUID.
        Schema::table('mobile_device_sessions', function (Blueprint $table) {
            $table->index(['shop_id', 'device_uuid'], 'mds_shop_device_uuid_idx');
        });

        // Partial unique index: at most one active (logged_out_at IS NULL) session per user per shop.
        // This is the DB-level safety net — the application logic is the primary enforcement.
        DB::statement(
            'CREATE UNIQUE INDEX mds_one_active_per_user ' .
            'ON mobile_device_sessions (user_id, shop_id) ' .
            'WHERE (logged_out_at IS NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_device_sessions');
    }
};

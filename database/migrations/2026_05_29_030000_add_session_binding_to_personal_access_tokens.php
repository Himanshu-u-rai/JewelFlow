<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 session governance — token-session binding.
 *
 * Adds two things:
 *
 * 1. personal_access_tokens.mobile_device_session_id (nullable FK)
 *    Links every mobile Sanctum token to the MobileDeviceSession row that
 *    issued it. When an owner revokes a session (via API or web), setting
 *    mobile_device_sessions.logged_out_at is sufficient to render the
 *    linked token permanently invalid — the EnforceSessionAlive middleware
 *    checks this on every authenticated mobile request.
 *
 *    SET NULL ON DELETE: if the Sanctum token is cleaned up first (pruned,
 *    expired), the session row is not affected (its state is preserved for
 *    audit). If the session row is deleted (retention prune), the FK is
 *    cleared but the token continues to work (backward-compat with any
 *    tokens that pre-date this migration).
 *
 * 2. mobile_device_sessions.locked_at (nullable timestamp)
 *    Records when the operator triggered the lock-screen action. The field
 *    is informational for the mobile client — the server does not enforce
 *    a "must unlock before acting" requirement (that is a UX concern).
 *    A NULL value means the session is NOT locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Bind tokens to sessions.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('mobile_device_session_id')
                ->nullable()
                ->after('abilities');

            $table->foreign('mobile_device_session_id')
                ->references('id')
                ->on('mobile_device_sessions')
                ->nullOnDelete();

            $table->index('mobile_device_session_id');
        });

        // 2. Add lock-screen state to session rows.
        Schema::table('mobile_device_sessions', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['mobile_device_session_id']);
            $table->dropIndex(['mobile_device_session_id']);
            $table->dropColumn('mobile_device_session_id');
        });

        Schema::table('mobile_device_sessions', function (Blueprint $table) {
            $table->dropColumn('locked_at');
        });
    }
};

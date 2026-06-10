<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds push-notification token storage to mobile_device_sessions.
 *
 * The push token lives on the device session (not the user) because push
 * delivery targets a physical device, and the session already models that
 * device's lifecycle (login -> active -> ended). When a session ends
 * (logout / replaced / revoked), the sender's `pushable` scope excludes it
 * via the existing logged_out_at column, so a dead session never receives push.
 *
 * - push_token: provider token (Expo: "ExponentPushToken[...]"). Nullable —
 *   set only after the client registers, cleared on opt-out / rotation.
 * - push_token_provider: 'expo' today; the column allows a future 'fcm' /
 *   'apns' without another schema change.
 * - push_token_updated_at: last registration time (staleness / debugging).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('mobile_device_sessions', function (Blueprint $table) {
            $table->string('push_token', 255)->nullable()->after('os_version');
            $table->string('push_token_provider', 20)->nullable()->after('push_token');
            $table->timestamp('push_token_updated_at')->nullable()->after('push_token_provider');

            // The sender only ever queries live sessions that carry a token.
            // Keeps targeted/broadcast push lookups cheap on busy shops.
            $table->index(['shop_id', 'push_token'], 'mds_shop_push_token_idx');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_device_sessions', function (Blueprint $table) {
            $table->dropIndex('mds_shop_push_token_idx');
            $table->dropColumn(['push_token', 'push_token_provider', 'push_token_updated_at']);
        });
    }
};

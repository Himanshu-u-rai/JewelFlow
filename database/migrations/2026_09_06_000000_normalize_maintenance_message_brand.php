<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Normalize the legacy "JewelFlow" brand in the stored platform maintenance
 * message to the canonical "JewelFlows".
 *
 * Only the exact legacy default value is rewritten — any administrator who has
 * customized the message keeps their text untouched. Re-running is a no-op once
 * the legacy value is gone (idempotent). The code-level default already reads
 * "JewelFlows", so new/unset installs need nothing.
 */
return new class extends Migration
{
    private const KEY = 'maintenance_message';

    private const LEGACY = "JewelFlow is temporarily down for maintenance. We'll be back shortly.";

    private const CANONICAL = "JewelFlows is temporarily down for maintenance. We'll be back shortly.";

    public function up(): void
    {
        $updated = DB::table('platform_settings')
            ->where('key', self::KEY)
            ->where('value', self::LEGACY)
            ->update(['value' => self::CANONICAL, 'updated_at' => now()]);

        if ($updated > 0) {
            Cache::forget('platform_setting:' . self::KEY);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: reverting would reintroduce the obsolete
        // "JewelFlow" brand (the very defect this migration fixes) and could
        // clobber a later administrator customization. Forward-only by design.
    }
};

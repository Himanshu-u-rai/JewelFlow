<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit N22 follow-up (2026-11-14): a shop with a pending GDPR deletion (platform_settings row
 * gdpr_pending_delete_shop_<id>) never moves to access_mode 'active'. The model hook and the bulk-action filter cover
 * Eloquent and one query; this covers every writer, including raw query-builder updates.
 *
 * Fires only when access_mode CHANGES to 'active', so a shop that already is 'active' is not blocked from ordinary
 * edits. Withdrawing a deletion = deleting its marker, after which the shop can be reactivated.
 *
 * CONSTITUTION §4 Rule 2: shops that are active right now with a pending marker (the N22 bug could leave some) are
 * listed and the migration stops; a person decides whether to suspend them again or withdraw the deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bad = DB::table('shops')
            ->join('platform_settings', 'platform_settings.key', '=', DB::raw("'gdpr_pending_delete_shop_' || shops.id::text"))
            ->where('shops.access_mode', 'active')
            ->orderBy('shops.id')
            ->pluck('shops.id');
        if ($bad->isNotEmpty()) {
            throw new RuntimeException('Cannot add shops_gdpr_pending_activation_guard: shops active while a GDPR deletion is pending; '
                . 'suspend them again or withdraw the deletion, then re-run. shop ids: ' . $bad->implode(', '));
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION shops_gdpr_pending_activation_guard()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.access_mode = 'active'
                   AND OLD.access_mode IS DISTINCT FROM 'active'
                   AND EXISTS (SELECT 1 FROM platform_settings WHERE key = 'gdpr_pending_delete_shop_' || NEW.id::text) THEN
                    RAISE EXCEPTION 'Shop % has a GDPR deletion pending and cannot be reactivated', NEW.id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS shops_gdpr_pending_activation_guard_trigger ON shops;
            CREATE TRIGGER shops_gdpr_pending_activation_guard_trigger
            BEFORE UPDATE OF access_mode ON shops
            FOR EACH ROW EXECUTE FUNCTION shops_gdpr_pending_activation_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS shops_gdpr_pending_activation_guard_trigger ON shops;
            DROP FUNCTION IF EXISTS shops_gdpr_pending_activation_guard();
        SQL);
    }
};

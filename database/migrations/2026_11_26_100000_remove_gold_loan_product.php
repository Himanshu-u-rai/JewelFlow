<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the retired gold-loan product ("dhiran") and the cross-product
 * promotion feature that only existed to advertise it.
 *
 * Earlier migrations that created these tables stay in history untouched (they
 * have run in production); this forward migration removes what they left:
 *   - the product's own tables and the four promotion tables;
 *   - its permissions (and their role grants), edition grants and edition
 *     requests, platform setting, announcements, password-reset tokens, per-shop
 *     counters, plans and platform product (plans/product are kept, inactive, only
 *     if billing history still references them);
 *   - the value 'dhiran' from the edition CHECK constraints.
 *
 * Accounts of that product (users.realm = 'dhiran') are deactivated, never
 * deleted: user rows are referenced from audit and financial history. They can
 * no longer sign in (login only resolves ERP accounts).
 *
 * Safety: in production this migration refuses to run when any loan, payment,
 * ledger, cash, attachment row or any such account exists. Production is
 * expected to have none; if it does, nothing is changed (PostgreSQL runs the
 * whole migration in one transaction) and the deploy stops for a decision.
 *
 * Not reversible: down() refuses. Restore from the pre-deploy backup instead.
 */
return new class extends Migration
{
    private const PRODUCT_TABLES = [
        // children first; no CASCADE, so an unexpected dependency fails loudly
        'dhiran_attachments',
        'dhiran_cash_entries',
        'dhiran_ledger_entries',
        'dhiran_payments',
        'dhiran_loan_items',
        'dhiran_loans',
        'dhiran_settings',
    ];

    private const PROMOTION_TABLES = [
        'product_recognitions',
        'product_recognition_requests',
        'product_promotion_exposures',
        'product_promotion_preferences',
    ];

    public function up(): void
    {
        $this->refuseIfProductionHasData();

        // Permissions and their role grants.
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->where('group', 'dhiran')->pluck('id');
            if ($ids->isNotEmpty() && Schema::hasTable('role_permission')) {
                DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
            }
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        // Edition grants and requests, then the CHECK constraints without the value.
        DB::table('shop_editions')->where('edition', 'dhiran')->delete();
        DB::table('shop_edition_requests')->where('edition', 'dhiran')->delete();
        DB::statement('ALTER TABLE shop_editions DROP CONSTRAINT IF EXISTS shop_editions_edition_check');
        DB::statement("ALTER TABLE shop_editions ADD CONSTRAINT shop_editions_edition_check
            CHECK (edition IN ('retailer', 'manufacturer', 'crm', 'analytics', 'mobile_premium'))");
        DB::statement('ALTER TABLE shop_edition_requests DROP CONSTRAINT IF EXISTS shop_edition_requests_edition_check');
        DB::statement("ALTER TABLE shop_edition_requests ADD CONSTRAINT shop_edition_requests_edition_check
            CHECK (edition IN ('retailer', 'manufacturer'))");

        // Platform switch, announcements aimed at the product or at the promotion toast.
        DB::table('platform_settings')->where('key', 'dhiran_enabled')->delete();
        if (Schema::hasColumn('platform_announcements', 'realm')) {
            DB::table('platform_announcements')->where('realm', 'dhiran')->delete();
        }
        DB::table('platform_announcements')->where('type', 'cross_promo')->delete();

        // Auth leftovers: reset tokens go; accounts are deactivated (see header).
        DB::table('password_reset_tokens')->where('realm', 'dhiran')->delete();
        // is_active must match employment_status (users_active_matches_employment_check).
        DB::table('users')->where('realm', 'dhiran')
            ->update(['is_active' => DB::raw('false'), 'employment_status' => 'suspended']);

        // Per-shop loan-number counters.
        DB::table('shop_counters')->where('counter_key', 'dhiran')->delete();

        // Plans and the platform product: delete when nothing references them,
        // otherwise keep them inactive so billing history still renders.
        $planIds = DB::table('plans')->where('code', 'like', 'dhiran\_%')->pluck('id');
        foreach ($planIds as $planId) {
            if ($this->isReferenced('plans', $planId)) {
                DB::table('plans')->where('id', $planId)->update(['is_active' => DB::raw('false')]);
            } else {
                DB::table('plans')->where('id', $planId)->delete();
            }
        }
        if (Schema::hasTable('platform_products')) {
            $productId = DB::table('platform_products')->where('code', 'dhiran')->value('id');
            if ($productId !== null) {
                if ($this->isReferenced('platform_products', $productId)) {
                    DB::table('platform_products')->where('id', $productId)->update(['is_active' => DB::raw('false')]);
                } else {
                    DB::table('platform_products')->where('id', $productId)->delete();
                }
            }
        }

        foreach (array_merge(self::PROMOTION_TABLES, self::PRODUCT_TABLES) as $table) {
            DB::statement('DROP TABLE IF EXISTS '.$table);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Not reversible: restore the pre-deploy backup to bring the removed product back.');
    }

    private function refuseIfProductionHasData(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $found = [];
        foreach (['dhiran_loans', 'dhiran_payments', 'dhiran_ledger_entries', 'dhiran_cash_entries', 'dhiran_attachments'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                $found[] = $table;
            }
        }
        if (DB::table('users')->where('realm', 'dhiran')->exists()) {
            $found[] = 'users.realm=dhiran';
        }

        if ($found !== []) {
            throw new RuntimeException('Gold-loan data exists in production ('.implode(', ', $found)
                .'); nothing was changed. Archive it and decide before removing the product.');
        }
    }

    /** True when any foreign key in the database points at this row. */
    private function isReferenced(string $table, int $id): bool
    {
        $refs = DB::select("
            SELECT cl.relname AS child, att.attname AS col
              FROM pg_constraint con
              JOIN pg_class cl  ON cl.oid = con.conrelid
              JOIN pg_class par ON par.oid = con.confrelid
              JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
             WHERE con.contype = 'f' AND par.relname = ? AND cl.relnamespace = 'public'::regnamespace
        ", [$table]);

        foreach ($refs as $ref) {
            if (DB::table($ref->child)->where($ref->col, $id)->exists()) {
                return true;
            }
        }

        return false;
    }
};

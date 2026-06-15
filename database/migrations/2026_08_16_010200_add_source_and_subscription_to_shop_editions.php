<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Track WHERE each edition grant came from, and (when applicable) WHICH paid
 * subscription backs it.
 *
 *   source = 'subscription'  → granted because a paid product subscription is active
 *   source = 'admin_grant'   → granted by a platform admin (demo / pilot / internal / trial / support)
 *   source = 'seed'          → seeded by migration / shop_type auto-seed
 *
 * Backfill: every PRE-EXISTING row is marked 'admin_grant'. This is the safe
 * default — it means an existing edition never gets revoked by a subscription
 * lapse (an admin_grant edition is never auto-revoked). No access is lost.
 *
 * product_subscription_id links a subscription-sourced edition back to the
 * exact shop_subscriptions row that paid for it. ON DELETE SET NULL so deleting
 * a subscription never cascades into dropping a shop's access.
 *
 * The edition CHECK constraint is extended to also allow the new product
 * edition strings (crm / analytics / mobile_premium) WITHOUT renaming the
 * existing retailer / manufacturer / dhiran strings that 15+ call-sites depend on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_editions', function (Blueprint $table): void {
            if (! Schema::hasColumn('shop_editions', 'source')) {
                $table->string('source', 24)->nullable()->after('edition');
            }
            if (! Schema::hasColumn('shop_editions', 'product_subscription_id')) {
                $table->foreignId('product_subscription_id')
                    ->nullable()
                    ->after('source')
                    ->constrained('shop_subscriptions')
                    ->nullOnDelete();
            }
        });

        // Backfill all existing rows → admin_grant (safe: never auto-revoked).
        DB::table('shop_editions')
            ->whereNull('source')
            ->update(['source' => 'admin_grant']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shop_editions DROP CONSTRAINT IF EXISTS shop_editions_edition_check');
            DB::statement(<<<'SQL'
                ALTER TABLE shop_editions
                ADD CONSTRAINT shop_editions_edition_check
                CHECK (edition IN ('retailer', 'manufacturer', 'dhiran', 'crm', 'analytics', 'mobile_premium'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shop_editions DROP CONSTRAINT IF EXISTS shop_editions_edition_check');
            DB::statement(<<<'SQL'
                ALTER TABLE shop_editions
                ADD CONSTRAINT shop_editions_edition_check
                CHECK (edition IN ('retailer', 'manufacturer', 'dhiran'))
            SQL);
        }

        Schema::table('shop_editions', function (Blueprint $table): void {
            if (Schema::hasColumn('shop_editions', 'product_subscription_id')) {
                $table->dropConstrainedForeignId('product_subscription_id');
            }
            if (Schema::hasColumn('shop_editions', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};

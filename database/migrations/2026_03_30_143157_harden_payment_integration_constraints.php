<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Unique constraint on razorpay_payment_id to prevent duplicate subscriptions
        Schema::table('shop_subscriptions', function (Blueprint $table) {
            $table->unique('razorpay_payment_id');
        });

        // 2. Make subscription_events.shop_subscription_id and admin_id nullable
        //    Required for payment.failed webhook events that have no associated subscription
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscription_events ALTER COLUMN shop_subscription_id DROP NOT NULL');
            DB::statement('ALTER TABLE subscription_events ALTER COLUMN admin_id DROP NOT NULL');

            // Drop existing FK constraints and re-add without restrictOnDelete for nullable columns
            DB::statement('ALTER TABLE subscription_events DROP CONSTRAINT IF EXISTS subscription_events_shop_subscription_id_foreign');
            DB::statement('ALTER TABLE subscription_events DROP CONSTRAINT IF EXISTS subscription_events_admin_id_foreign');

            DB::statement('ALTER TABLE subscription_events ADD CONSTRAINT subscription_events_shop_subscription_id_foreign
                FOREIGN KEY (shop_subscription_id) REFERENCES shop_subscriptions(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE subscription_events ADD CONSTRAINT subscription_events_admin_id_foreign
                FOREIGN KEY (admin_id) REFERENCES platform_admins(id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Restore NOT NULL only if no null rows exist
            $nullSubRows = DB::table('subscription_events')->whereNull('shop_subscription_id')->count();
            $nullAdminRows = DB::table('subscription_events')->whereNull('admin_id')->count();

            if ($nullSubRows === 0 && $nullAdminRows === 0) {
                DB::statement('ALTER TABLE subscription_events DROP CONSTRAINT IF EXISTS subscription_events_shop_subscription_id_foreign');
                DB::statement('ALTER TABLE subscription_events DROP CONSTRAINT IF EXISTS subscription_events_admin_id_foreign');

                DB::statement('ALTER TABLE subscription_events ALTER COLUMN shop_subscription_id SET NOT NULL');
                DB::statement('ALTER TABLE subscription_events ALTER COLUMN admin_id SET NOT NULL');

                DB::statement('ALTER TABLE subscription_events ADD CONSTRAINT subscription_events_shop_subscription_id_foreign
                    FOREIGN KEY (shop_subscription_id) REFERENCES shop_subscriptions(id) ON DELETE RESTRICT');
                DB::statement('ALTER TABLE subscription_events ADD CONSTRAINT subscription_events_admin_id_foreign
                    FOREIGN KEY (admin_id) REFERENCES platform_admins(id) ON DELETE RESTRICT');
            }
        }

        Schema::table('shop_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['razorpay_payment_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (!Schema::hasColumn('plans', 'trial_days')) {
                $table->unsignedInteger('trial_days')->nullable()->default(0)->after('price_yearly');
            }
            if (!Schema::hasColumn('plans', 'razorpay_monthly_plan_id')) {
                $table->string('razorpay_monthly_plan_id')->nullable()->after('trial_days');
            }
            if (!Schema::hasColumn('plans', 'razorpay_yearly_plan_id')) {
                $table->string('razorpay_yearly_plan_id')->nullable()->after('razorpay_monthly_plan_id');
            }
        });

        Schema::table('shop_subscriptions', function (Blueprint $table): void {
            if (!Schema::hasColumn('shop_subscriptions', 'razorpay_payment_id')) {
                $table->string('razorpay_payment_id')->nullable()->after('status');
            }
            if (!Schema::hasColumn('shop_subscriptions', 'razorpay_order_id')) {
                $table->string('razorpay_order_id')->nullable()->after('razorpay_payment_id');
            }
            if (!Schema::hasColumn('shop_subscriptions', 'billing_cycle')) {
                $table->string('billing_cycle')->nullable()->after('grace_ends_at');
            }
            if (!Schema::hasColumn('shop_subscriptions', 'price_paid')) {
                $table->decimal('price_paid', 10, 2)->nullable()->after('billing_cycle');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shop_subscriptions ALTER COLUMN shop_id DROP NOT NULL');
            DB::statement('ALTER TABLE subscription_events ALTER COLUMN shop_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('shop_subscriptions', function (Blueprint $table): void {
            foreach (['price_paid', 'billing_cycle', 'razorpay_order_id', 'razorpay_payment_id'] as $column) {
                if (Schema::hasColumn('shop_subscriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('plans', function (Blueprint $table): void {
            foreach (['razorpay_yearly_plan_id', 'razorpay_monthly_plan_id', 'trial_days'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            $pendingSubscriptions = DB::table('shop_subscriptions')->whereNull('shop_id')->count();
            $pendingEvents = DB::table('subscription_events')->whereNull('shop_id')->count();

            if ($pendingSubscriptions > 0 || $pendingEvents > 0) {
                throw new RuntimeException('Cannot restore NOT NULL on subscription shop_id columns while pending onboarding records exist.');
            }

            DB::statement('ALTER TABLE shop_subscriptions ALTER COLUMN shop_id SET NOT NULL');
            DB::statement('ALTER TABLE subscription_events ALTER COLUMN shop_id SET NOT NULL');
        }
    }
};

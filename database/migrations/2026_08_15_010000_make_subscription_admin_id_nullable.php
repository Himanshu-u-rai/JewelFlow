<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Self-service and webhook-driven purchases have no platform admin behind
        // them, so updated_by_admin_id can no longer be NOT NULL. The FK constraint
        // stays — it just becomes nullable.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shop_subscriptions ALTER COLUMN updated_by_admin_id DROP NOT NULL');
        }

        // Record who initiated each subscription change: 'admin', 'self_service',
        // 'system', or 'webhook'. Nullable so existing rows are untouched.
        Schema::table('shop_subscriptions', function (Blueprint $table): void {
            if (!Schema::hasColumn('shop_subscriptions', 'actor_type')) {
                $table->string('actor_type', 20)->nullable()->after('updated_by_admin_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_subscriptions', function (Blueprint $table): void {
            if (Schema::hasColumn('shop_subscriptions', 'actor_type')) {
                $table->dropColumn('actor_type');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            $nullAdminRows = DB::table('shop_subscriptions')->whereNull('updated_by_admin_id')->count();

            if ($nullAdminRows > 0) {
                throw new RuntimeException('Cannot restore NOT NULL on shop_subscriptions.updated_by_admin_id while rows with a null admin exist.');
            }

            DB::statement('ALTER TABLE shop_subscriptions ALTER COLUMN updated_by_admin_id SET NOT NULL');
        }
    }
};

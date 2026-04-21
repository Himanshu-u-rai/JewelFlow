<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Loyalty configuration in shop_preferences
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points_per_hundred')->default(1)->after('round_off_nearest');
            $table->decimal('loyalty_point_value', 8, 2)->default(0.25)->after('loyalty_points_per_hundred');
            $table->unsignedSmallInteger('loyalty_expiry_months')->default(12)->after('loyalty_point_value');
        });

        // Expiry tracking on loyalty_transactions
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('balance_after');
            $table->boolean('expired')->default(false)->after('expires_at');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'expired']);
        });

        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points_per_hundred', 'loyalty_point_value', 'loyalty_expiry_months']);
        });
    }
};

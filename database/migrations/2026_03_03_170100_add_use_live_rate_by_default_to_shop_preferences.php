<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table): void {
            if (!Schema::hasColumn('shop_preferences', 'use_live_rate_by_default')) {
                $table->boolean('use_live_rate_by_default')->default(false)->after('low_stock_threshold');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table): void {
            if (Schema::hasColumn('shop_preferences', 'use_live_rate_by_default')) {
                $table->dropColumn('use_live_rate_by_default');
            }
        });
    }
};

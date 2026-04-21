<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->unsignedSmallInteger('round_off_nearest')->default(1)->after('low_stock_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn('round_off_nearest');
        });
    }
};

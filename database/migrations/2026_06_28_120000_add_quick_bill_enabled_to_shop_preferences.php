<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            // Default true so existing shops keep Quick Bill available.
            $table->boolean('quick_bill_enabled')->default(true)->after('low_stock_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn('quick_bill_enabled');
        });
    }
};

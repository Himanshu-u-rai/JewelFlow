<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add shop_type to shops — defaults to 'manufacturer' so existing shops keep working
        Schema::table('shops', function (Blueprint $table) {
            $table->string('shop_type', 20)->default('manufacturer')->after('name');
        });

        // 2. Add retailer-specific fields to items
        Schema::table('items', function (Blueprint $table) {
            // selling_price: the MRP / tag price for retailer shops
            $table->decimal('selling_price', 12, 2)->nullable()->after('cost_price');
            // source: how this item entered inventory
            $table->string('source', 30)->default('manufactured')->after('selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('shop_type');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['selling_price', 'source']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Add shop_id if it doesn't exist
            if (!Schema::hasColumn('items', 'shop_id')) {
                $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->onDelete('cascade');
            }
            
            // Add sub_category
            if (!Schema::hasColumn('items', 'sub_category')) {
                $table->string('sub_category')->nullable()->after('category');
            }
            
            // Add wastage, making_charges, stone_charges, cost_price
            if (!Schema::hasColumn('items', 'wastage')) {
                $table->decimal('wastage', 18, 6)->default(0)->after('metal_lot_id');
            }
            
            if (!Schema::hasColumn('items', 'making_charges')) {
                $table->decimal('making_charges', 18, 2)->default(0)->after('wastage');
            }
            
            if (!Schema::hasColumn('items', 'stone_charges')) {
                $table->decimal('stone_charges', 18, 2)->default(0)->after('making_charges');
            }
            
            if (!Schema::hasColumn('items', 'cost_price')) {
                $table->decimal('cost_price', 18, 2)->default(0)->after('stone_charges');
            }
            
            if (!Schema::hasColumn('items', 'product_id')) {
                // Keep this migration order-safe: products table may not exist yet.
                $table->unsignedBigInteger('product_id')->nullable()->after('cost_price');
            }
            
            // Make design nullable
            $table->string('design')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['sub_category', 'wastage', 'making_charges', 'stone_charges', 'cost_price', 'product_id', 'shop_id']);
        });
    }
};

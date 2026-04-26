<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_purchase_items', function (Blueprint $table) {
            $table->foreignId('metal_lot_id')
                ->nullable()
                ->after('item_id')
                ->constrained('metal_lots')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_purchase_items', function (Blueprint $table) {
            $table->dropForeign(['metal_lot_id']);
            $table->dropColumn('metal_lot_id');
        });
    }
};

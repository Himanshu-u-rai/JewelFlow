<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'stock_purchase_id')) {
                $table->unsignedBigInteger('stock_purchase_id')->nullable()->after('id');
                $table->foreign('stock_purchase_id')->references('id')->on('stock_purchases')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'stock_purchase_id')) {
                $table->dropForeign(['stock_purchase_id']);
                $table->dropColumn('stock_purchase_id');
            }
        });
    }
};

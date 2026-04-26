<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedBigInteger('job_order_id')->nullable()->after('stock_purchase_id');
            $table->foreign('job_order_id')->references('id')->on('job_orders')->nullOnDelete();
            $table->index(['shop_id', 'job_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['job_order_id']);
            $table->dropIndex(['shop_id', 'job_order_id']);
            $table->dropColumn('job_order_id');
        });
    }
};

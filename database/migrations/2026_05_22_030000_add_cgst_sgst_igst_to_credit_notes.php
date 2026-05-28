<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->decimal('cgst_amount', 18, 2)->nullable()->after('gst');
            $table->decimal('sgst_amount', 18, 2)->nullable()->after('cgst_amount');
            $table->decimal('igst_amount', 18, 2)->nullable()->after('sgst_amount');
            $table->foreignId('exchange_order_id')->nullable()->constrained('exchange_orders')->nullOnDelete()->after('return_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropForeign(['exchange_order_id']);
            $table->dropColumn(['cgst_amount', 'sgst_amount', 'igst_amount', 'exchange_order_id']);
        });
    }
};

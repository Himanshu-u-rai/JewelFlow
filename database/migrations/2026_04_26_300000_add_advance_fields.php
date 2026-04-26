<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->decimal('advance_amount', 14, 2)->default(0)->after('notes');
            $table->string('advance_mode', 20)->nullable()->after('advance_amount');
            $table->foreignId('advance_payment_method_id')
                ->nullable()
                ->after('advance_mode')
                ->constrained('shop_payment_methods')
                ->nullOnDelete();
        });

        Schema::table('karigar_payments', function (Blueprint $table) {
            $table->foreignId('job_order_id')
                ->nullable()
                ->after('karigar_invoice_id')
                ->constrained('job_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('karigar_payments', function (Blueprint $table) {
            $table->dropForeign(['job_order_id']);
            $table->dropColumn('job_order_id');
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropForeign(['advance_payment_method_id']);
            $table->dropColumn(['advance_amount', 'advance_mode', 'advance_payment_method_id']);
        });
    }
};

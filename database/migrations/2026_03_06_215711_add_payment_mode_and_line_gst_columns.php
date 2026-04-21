<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add payment_mode to cash_transactions so each record is per-mode
        Schema::table('cash_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('cash_transactions', 'payment_mode')) {
                $table->string('payment_mode')->nullable()->after('description');
            }
        });

        // Add per-line GST columns to invoice_items
        Schema::table('invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_items', 'gst_rate')) {
                $table->decimal('gst_rate', 5, 2)->default(0)->after('line_total');
            }
            if (!Schema::hasColumn('invoice_items', 'gst_amount')) {
                $table->decimal('gst_amount', 18, 2)->default(0)->after('gst_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['gst_rate', 'gst_amount']);
        });
    }
};

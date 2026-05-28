<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('cgst_amount', 18, 2)->nullable()->after('gst');
            $table->decimal('sgst_amount', 18, 2)->nullable()->after('cgst_amount');
            $table->decimal('igst_amount', 18, 2)->nullable()->after('sgst_amount');
            $table->boolean('cgst_was_backfilled')->default(false)->after('igst_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['cgst_amount', 'sgst_amount', 'igst_amount', 'cgst_was_backfilled']);
        });
    }
};

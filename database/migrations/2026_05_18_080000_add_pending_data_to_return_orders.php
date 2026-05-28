<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_orders', function (Blueprint $table) {
            // Settlement method chosen at return time: 'cash' or 'store_credit'.
            $table->string('refund_settlement', 20)->nullable()->after('settled_at');

            // Serialised selections + reason for returns awaiting manager approval.
            $table->jsonb('pending_data')->nullable()->after('refund_settlement');
        });
    }

    public function down(): void
    {
        Schema::table('return_orders', function (Blueprint $table) {
            $table->dropColumn(['refund_settlement', 'pending_data']);
        });
    }
};

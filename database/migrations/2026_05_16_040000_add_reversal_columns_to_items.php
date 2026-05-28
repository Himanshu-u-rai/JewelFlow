<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('job_order_receipt_item_id')
                  ->nullable()
                  ->after('job_order_id')
                  ->constrained('job_order_receipt_items')
                  ->nullOnDelete();

            $table->timestamp('reversed_at')->nullable()->after('pricing_review_required');
            $table->text('reversal_reason')->nullable()->after('reversed_at');
            $table->foreignId('reversed_by')
                  ->nullable()
                  ->after('reversal_reason')
                  ->constrained('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['job_order_receipt_item_id']);
            $table->dropColumn('job_order_receipt_item_id');
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['reversed_at', 'reversal_reason', 'reversed_by']);
        });
    }
};

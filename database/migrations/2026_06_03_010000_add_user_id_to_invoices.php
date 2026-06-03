<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting #16 — operator (seller) attribution on invoices.
 *
 * Additive, NULLABLE column. Captured at invoice CREATION (the draft write),
 * never on the finalized/locked path, so it does not touch any accounting
 * figure, guard, or trigger. Legacy invoices keep NULL (reported as
 * "Unattributed"); no backfill — we cannot know who created historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices') || Schema::hasColumn('invoices', 'user_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('customer_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['shop_id', 'user_id'], 'invoices_shop_user_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'user_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_shop_user_index');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

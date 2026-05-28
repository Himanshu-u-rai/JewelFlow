<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Locks line-level shares of invoice-level totals (discount, round_off,
     * loyalty points) onto the line at sale-finalize time. Returns deduct
     * from these pre-computed values — never recompute proportions on the fly.
     * This eliminates rounding drift across multiple partial returns. See
     * architecture §3.
     *
     * Also adds return-tracking columns so we can quickly check "has this
     * specific invoice_item been returned yet?" and follow the link to the
     * return_line_item that did it.
     *
     * existing line_total + gst_amount already serve as allocated_subtotal and
     * allocated_gst — no need to duplicate.
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // Locked allocations of invoice-level totals.
            $table->decimal('allocated_discount', 18, 2)->default(0)->after('gst_amount');
            $table->decimal('allocated_round_off', 18, 4)->default(0)->after('allocated_discount');
            $table->integer('allocated_loyalty_pts')->default(0)->after('allocated_round_off');

            // Return tracking — single piece per line, so binary "returned or not".
            $table->timestamp('returned_at')->nullable()->after('allocated_loyalty_pts');
            $table->foreignId('return_line_item_id')->nullable()->after('returned_at')
                  ->constrained('return_line_items')->nullOnDelete();

            $table->index(['invoice_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['return_line_item_id']);
            $table->dropIndex(['invoice_id', 'returned_at']);
            $table->dropColumn([
                'allocated_discount',
                'allocated_round_off',
                'allocated_loyalty_pts',
                'returned_at',
                'return_line_item_id',
            ]);
        });
    }
};

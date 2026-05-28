<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-line return rows. Each row says "this specific invoice_item is being
     * returned by this return_order, here's the refund attributed, here's the
     * condition the piece came back in."
     *
     * Each invoice_item is one physical piece (unique barcode, item_id), so we
     * don't need a quantity dimension — a line is either returned or not. The
     * `return_line_items_no_duplicate_invoice_item` unique guard enforces "you
     * can't return the same line twice."
     */
    public function up(): void
    {
        Schema::create('return_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('return_order_id')->constrained('return_orders')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();

            // Snapshot of refund components attributable to this line, locked
            // at return-settlement time. Source values come from invoice_items.line_total,
            // invoice_items.gst_amount, and the allocated_* columns we add in M6.
            $table->decimal('refund_subtotal', 18, 2);
            $table->decimal('refund_gst', 18, 2);
            $table->decimal('refund_discount', 18, 2)->default(0);
            $table->decimal('refund_round_off', 18, 4)->default(0);
            $table->integer('refund_loyalty_pts')->default(0);
            $table->decimal('refund_total', 18, 2);  // = subtotal + gst - discount + round_off

            // Condition assessed at return time. Drives the default disposition
            // suggestion. Phase 1 mostly defaults to good_condition for full-invoice
            // returns; Phase 2 lets the cashier set it per line.
            $table->string('condition', 24)->default('good_condition');

            // Per-line reason. Inherits from header if not set.
            $table->string('reason')->nullable();

            $table->timestamps();

            // Guard: a single invoice_item can be returned only once. Since each
            // invoice_item represents one physical piece, returning it twice is
            // a data error.
            $table->unique('invoice_item_id', 'return_line_items_invoice_item_unique');

            $table->index(['shop_id', 'return_order_id']);
        });

        // condition CHECK: explicit allow-list.
        DB::statement(
            "ALTER TABLE return_line_items ADD CONSTRAINT return_line_items_condition_check "
            . "CHECK (condition IN ('good_condition','minor_wear','damaged','non_sellable'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('return_line_items');
    }
};

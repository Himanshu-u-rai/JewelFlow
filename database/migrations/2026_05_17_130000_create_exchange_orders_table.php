<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 — Exchanges. The header that pairs a return + a new sale + a
     * net settlement into a single user-facing transaction. See architecture §4.
     *
     * MVP scope:
     *   - one return_order + one new invoice + cash-only settlement
     *   - status flips draft → settled atomically (no multi-step wizard yet)
     *   - cancelled reachable only from draft
     * Future:
     *   - wizard with drafting_return / drafting_valuation / drafting_sale /
     *     drafting_settlement intermediate states (Phase 3B)
     *   - store_credit settlement (Phase 4)
     */
    public function up(): void
    {
        Schema::create('exchange_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // The two halves of the exchange. Both can be null in 'draft' state
            // but must be set by 'settled'.
            $table->foreignId('return_order_id')->nullable()->constrained('return_orders')->restrictOnDelete();
            $table->foreignId('new_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // Valuation rules applied to the returned piece(s). 'sale_day_rate'
            // and 'today_rate' are Phase 3A; fixed_rate/manual_override come later.
            $table->string('valuation_basis_source', 32);
            $table->decimal('valuation_rate_override', 12, 2)->nullable();

            // Settlement: signed net amount.
            // Positive = customer owes shop (i.e. new sale > return).
            // Negative = shop refunds customer (return > new sale).
            // Zero = even swap.
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->string('payment_method', 32)->default('cash');

            $table->string('status', 16)->default('draft');
            $table->text('reason')->nullable();

            // Lifecycle
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            // Approval (for manual_override valuation)
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->unique('return_order_id', 'exchange_orders_return_unique');
            $table->unique('new_invoice_id', 'exchange_orders_invoice_unique');
        });

        DB::statement(
            "ALTER TABLE exchange_orders ADD CONSTRAINT exchange_orders_status_check "
            . "CHECK (status IN ('draft','settled','cancelled'))"
        );

        DB::statement(
            "ALTER TABLE exchange_orders ADD CONSTRAINT exchange_orders_basis_check "
            . "CHECK (valuation_basis_source IN ('sale_day_rate','today_rate','fixed_rate','manual_override'))"
        );

        DB::statement(
            "ALTER TABLE exchange_orders ADD CONSTRAINT exchange_orders_payment_check "
            . "CHECK (payment_method IN ('cash','store_credit','mixed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_orders');
    }
};

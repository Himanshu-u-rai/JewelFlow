<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 of the Returns & Exchanges domain — header table for return events.
     *
     * One row per "I want to return / exchange these items from invoice X."
     * Status lifecycle: draft → (pending_approval) → submitted → settled, with
     * cancelled reachable only from draft/pending_approval. See the architecture
     * doc, section 7, for full state machine.
     *
     * return_type carries the operational semantics that the architecture review
     * (section 1) flagged as needing to be at the header, not on the item.
     */
    public function up(): void
    {
        Schema::create('return_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // 'customer_return' is the only allowed value in Phase 1. The CHECK
            // expands in later phases (customer_exchange, warranty_claim, buyback,
            // supplier_return, inspection_hold).
            $table->string('return_type', 32);

            // State machine — see architecture §7. ImmutableLedger applied at the
            // model layer once status='settled'.
            $table->string('status', 24)->default('draft');

            // Free-text reason captured at draft time. min:5/max:500 enforced at
            // the service layer; DB just stores text.
            $table->text('reason')->nullable();

            // Out-of-window return: when the shop has a return policy with a
            // window (e.g. 7 days) and the cashier processed something past it,
            // an override flag with audit. Recorded who approved.
            $table->boolean('return_window_violation_override')->default(false);
            $table->foreignId('override_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('override_approved_at')->nullable();

            // Lifecycle people / timestamps.
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'invoice_id']);
        });

        // Status CHECK: explicit allow-list. Expand if new states are added.
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE return_orders ADD CONSTRAINT return_orders_status_check "
            . "CHECK (status IN ('draft','pending_approval','submitted','settled','cancelled'))"
        );

        // return_type CHECK: only 'customer_return' allowed in Phase 1. Later
        // migrations expand this list as new return types come online.
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE return_orders ADD CONSTRAINT return_orders_type_check "
            . "CHECK (return_type IN ('customer_return'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('return_orders');
    }
};

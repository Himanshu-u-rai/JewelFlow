<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-owner in-app notification feed (sale alerts today; `type` reserved for
 * future kinds). One row per recipient owner per event — the push and the feed
 * row are written together by SaleNotificationService after the sale commits.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type')->default('sale');          // reserved for future types
            $table->string('counter_type');                    // 'pos' | 'quick_bill'
            $table->string('actor_name');                      // operator who made the sale
            $table->decimal('amount', 14, 2);
            $table->string('customer_name')->nullable();       // null = walk-in
            $table->unsignedBigInteger('invoice_id');          // source record id
            $table->string('invoice_type');                    // 'invoice' | 'quick_bill'
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'recipient_user_id', 'read_at']);    // unread-count
            $table->index(['shop_id', 'recipient_user_id', 'created_at']); // feed sort
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_notifications');
    }
};

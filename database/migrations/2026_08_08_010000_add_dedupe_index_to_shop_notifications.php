<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index for the observer dedupe guard: (invoice_id, invoice_type) is globally
 * unique per sale, so SaleNotificationService can cheaply check "already
 * emitted?" before writing rows. Makes the emit idempotent regardless of how
 * many times the created/updated observer fires for one sale.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop_notifications', function (Blueprint $table) {
            $table->index(['invoice_id', 'invoice_type'], 'shop_notifications_dedupe_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shop_notifications', function (Blueprint $table) {
            $table->dropIndex('shop_notifications_dedupe_idx');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTERS PART 3 — give Customer the same archive/reactivate lifecycle Vendor
 * already has.
 *
 * Mirrors the Vendor column exactly (migration 2026_03_05_120000_retailer_features):
 * a NOT NULL boolean defaulting to TRUE plus a (shop_id, is_active) composite
 * index, so the per-tenant "active customers" lookup that every selector runs is
 * index-served.
 *
 * DELIBERATELY NOT SoftDeletes and DELIBERATELY NOT a global scope. An archived
 * customer must stay fully visible in invoices, gold ledgers, loyalty history,
 * returns and reports — a global scope would silently hide those historical
 * relations. Filtering is opt-in via Customer::active() on the enumerated
 * new-commitment paths only (see MASTERS-PART3-CONSUMER-LEDGER.md).
 *
 * Every existing customer becomes active: the column is added with DEFAULT TRUE
 * and NOT NULL, so Postgres backfills each existing row to TRUE in place. No
 * customer data is read, rewritten or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'is_active')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers', 'is_active')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'is_active']);
            $table->dropColumn('is_active');
        });
    }
};

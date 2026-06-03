<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MC-1 foundation: additive, nullable making-charge mode metadata on items.
 *
 * `making_charge_value` is the RAW input (₹ for fixed, % for percentage,
 * ₹/g for per_gram). `making_charges` (existing) stays the RESOLVED ₹ amount
 * and the only accounting truth. NULL type ⇒ FIXED (legacy behaviour).
 *
 * Additive only — no defaults that alter behaviour, no backfill, rollback-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'making_charge_type')) {
                $table->string('making_charge_type', 16)->nullable()->after('making_charges');
            }
            if (! Schema::hasColumn('items', 'making_charge_value')) {
                $table->decimal('making_charge_value', 12, 2)->nullable()->after('making_charge_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            foreach (['making_charge_type', 'making_charge_value'] as $col) {
                if (Schema::hasColumn('items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

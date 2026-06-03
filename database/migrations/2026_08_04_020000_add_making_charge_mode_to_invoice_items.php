<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MC-1 foundation: additive, nullable making-charge mode snapshot on
 * invoice_items. Captured at sale time (MC-3) so a finalized line is fully
 * reproducible (mode + raw value + the resolved ₹ already in making_charges).
 *
 * These columns are write-once at line creation (like making_charges) — they
 * are NOT added to any allowed-update set; ImmutableLedger still forbids
 * updates to finalized lines. NULL type ⇒ FIXED. Additive, rollback-safe,
 * unreferenced by the accounting-guard trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'making_charge_type')) {
                $table->string('making_charge_type', 16)->nullable()->after('making_charges');
            }
            if (! Schema::hasColumn('invoice_items', 'making_charge_value')) {
                $table->decimal('making_charge_value', 12, 2)->nullable()->after('making_charge_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            foreach (['making_charge_type', 'making_charge_value'] as $col) {
                if (Schema::hasColumn('invoice_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

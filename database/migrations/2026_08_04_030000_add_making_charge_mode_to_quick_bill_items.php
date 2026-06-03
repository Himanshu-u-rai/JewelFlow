<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MC-1 foundation: additive, nullable making-charge mode metadata on
 * quick_bill_items. (Quick Bill already carries a percentage axis for
 * wastage_percent; this adds the same axis for making.) `making_charge`
 * (existing) stays the resolved ₹. NULL type ⇒ FIXED. Additive, rollback-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_bill_items', function (Blueprint $table) {
            if (! Schema::hasColumn('quick_bill_items', 'making_charge_type')) {
                $table->string('making_charge_type', 16)->nullable()->after('making_charge');
            }
            if (! Schema::hasColumn('quick_bill_items', 'making_charge_value')) {
                $table->decimal('making_charge_value', 12, 2)->nullable()->after('making_charge_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quick_bill_items', function (Blueprint $table) {
            foreach (['making_charge_type', 'making_charge_value'] as $col) {
                if (Schema::hasColumn('quick_bill_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

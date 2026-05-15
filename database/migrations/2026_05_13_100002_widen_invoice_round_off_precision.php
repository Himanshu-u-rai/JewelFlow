<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen invoices.round_off from numeric(10,2) to numeric(10,4).
     *
     * Rationale: when shop rounding nearest is 0.01 we need finer intermediate
     * precision than 2dp; storing at 4dp avoids truncation drift before the
     * PricingEngine final-rounds for display.
     *
     * Safety: the existing `invoices_accounting_guard` trigger (see
     * 2026_03_06_215712_add_immutability_guards_for_discount_roundoff_gstrate.php)
     * compares totals via ROUND(..., 2), so a wider round_off column still
     * satisfies the trigger's identity `total = subtotal + gst + wastage -
     * discount + round_off`. The trigger itself is NOT modified here.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PG can't widen scale via a plain ALTER without an explicit USING
            // cast in some pathological cases; provide it for safety.
            DB::statement(
                'ALTER TABLE invoices ALTER COLUMN round_off TYPE numeric(10,4) USING round_off::numeric(10,4)'
            );
            return;
        }

        // MySQL / SQLite fallback (dev environments). Laravel 12 supports
        // ->change() natively without doctrine/dbal.
        \Illuminate\Support\Facades\Schema::table('invoices', function ($table) {
            $table->decimal('round_off', 10, 4)->change();
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Cast back to 2dp; any rows with sub-0.01 fractions will be
            // rounded by the explicit USING cast, which is the desired
            // behaviour on a rollback.
            DB::statement(
                'ALTER TABLE invoices ALTER COLUMN round_off TYPE numeric(10,2) USING round_off::numeric(10,2)'
            );
            return;
        }

        \Illuminate\Support\Facades\Schema::table('invoices', function ($table) {
            $table->decimal('round_off', 10, 2)->change();
        });
    }
};

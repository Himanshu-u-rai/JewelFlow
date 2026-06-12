<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair module closure: gold_issued_fine / gold_returned_fine were never
 * written by any flow (0 populated rows) and modelled a metal-accounting
 * workflow that the JobOrder repair path owns, not the repair service ticket.
 * They surfaced as permanently-zero "Gold Issued/Returned" totals in the repair
 * report — fake numbers. Remove the dead columns; the report stops showing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE repairs DROP COLUMN IF EXISTS gold_issued_fine');
        DB::statement('ALTER TABLE repairs DROP COLUMN IF EXISTS gold_returned_fine');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE repairs ADD COLUMN IF NOT EXISTS gold_issued_fine NUMERIC(18,6) NULL');
        DB::statement('ALTER TABLE repairs ADD COLUMN IF NOT EXISTS gold_returned_fine NUMERIC(18,6) NULL');
    }
};

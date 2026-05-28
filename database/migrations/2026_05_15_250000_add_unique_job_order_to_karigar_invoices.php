<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prevent two karigar invoices from being created for the same job order.
 *
 * A job order represents a single work engagement (one challan, one karigar).
 * Creating a second invoice for the same job order would result in double
 * payment for the same work. The constraint is partial (WHERE job_order_id IS
 * NOT NULL) so stand-alone invoices (no linked job order) are unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX karigar_invoices_unique_job_order
            ON karigar_invoices (shop_id, job_order_id)
            WHERE job_order_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS karigar_invoices_unique_job_order');
    }
};

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // A. Add 'rework' to job_orders.job_type CHECK constraint
        DB::statement('ALTER TABLE job_orders DROP CONSTRAINT IF EXISTS job_orders_job_type_check');
        DB::statement("ALTER TABLE job_orders ADD CONSTRAINT job_orders_job_type_check CHECK (job_type IN ('manufacture','repair','rework'))");
    }
    public function down(): void
    {
        DB::statement('ALTER TABLE job_orders DROP CONSTRAINT IF EXISTS job_orders_job_type_check');
        DB::statement("ALTER TABLE job_orders ADD CONSTRAINT job_orders_job_type_check CHECK (job_type IN ('manufacture','repair'))");
    }
};

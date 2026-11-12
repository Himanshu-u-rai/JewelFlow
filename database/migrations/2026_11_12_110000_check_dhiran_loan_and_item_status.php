<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The loan and item states are a closed list (owner 2026-11-12, state-machine audit); the database refuses anything
 * else, so a typo or a raw update cannot invent a state no screen or rule knows about.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE dhiran_loans ADD CONSTRAINT chk_dhiran_loans_status CHECK (status IN ('pending_evidence', 'active', 'closed', 'renewed', 'forfeited'))");
        DB::statement("ALTER TABLE dhiran_loan_items ADD CONSTRAINT chk_dhiran_loan_items_status CHECK (status IN ('pledged', 'released', 'forfeited'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dhiran_loans DROP CONSTRAINT IF EXISTS chk_dhiran_loans_status');
        DB::statement('ALTER TABLE dhiran_loan_items DROP CONSTRAINT IF EXISTS chk_dhiran_loan_items_status');
    }
};

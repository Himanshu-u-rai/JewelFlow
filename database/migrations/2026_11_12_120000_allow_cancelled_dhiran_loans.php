<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A loan awaiting evidence can be cancelled (owner 2026-11-12): 'cancelled' joins the closed list of loan states. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE dhiran_loans DROP CONSTRAINT IF EXISTS chk_dhiran_loans_status');
        DB::statement("ALTER TABLE dhiran_loans ADD CONSTRAINT chk_dhiran_loans_status CHECK (status IN ('pending_evidence', 'active', 'closed', 'renewed', 'forfeited', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dhiran_loans DROP CONSTRAINT IF EXISTS chk_dhiran_loans_status');
        DB::statement("ALTER TABLE dhiran_loans ADD CONSTRAINT chk_dhiran_loans_status CHECK (status IN ('pending_evidence', 'active', 'closed', 'renewed', 'forfeited'))");
    }
};

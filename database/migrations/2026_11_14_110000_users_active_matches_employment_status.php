<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit N23: is_active (read by login and EnsureAccountIsActive) and employment_status (why) must agree, and the
 * database says so, so a raw bulk update that skips UserObserver cannot drift them.
 *
 * Existing inconsistent rows are NOT rewritten here: which column was meant is an owner decision. The migration
 * stops and lists them; fix them through the staff flows, then re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bad = DB::table('users')
            ->whereRaw("is_active IS DISTINCT FROM (employment_status = 'active')")
            ->orderBy('id')
            ->get(['id', 'shop_id', 'is_active', 'employment_status']);

        if ($bad->isNotEmpty()) {
            $list = $bad->map(fn ($u) => "user {$u->id} (shop {$u->shop_id}): is_active=" . ($u->is_active ? 'true' : 'false') . ", employment_status={$u->employment_status}")->implode('; ');
            throw new RuntimeException("Cannot add users_active_matches_employment_check: {$bad->count()} user(s) have is_active and employment_status out of step. Resolve them, then re-run. {$list}");
        }

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_active_matches_employment_check
            CHECK (is_active = (employment_status = 'active'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_active_matches_employment_check');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill employment_status for users that were deactivated (is_active = false)
 * before the employment lifecycle columns existed.
 *
 * When 2026_05_15_190000 ran, it defaulted every row to employment_status = 'active'.
 * Any user with is_active = false that predates the employment lifecycle system
 * now has an inconsistent state: is_active = false + employment_status = 'active'.
 *
 * This migration synchronises those rows so employment_status reflects reality.
 * No is_active values are changed — we trust is_active as the source of truth for
 * rows that existed before the employment lifecycle was introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Rows where is_active = false but employment_status still says 'active'
        // are users who were deactivated outside the new lifecycle flow.
        // Treat them as terminated: this is the safest interpretation —
        // if they were ever reactivated by the owner, the Reactivate flow will
        // set employment_status = 'active' explicitly.
        $affected = DB::statement("
            UPDATE users
            SET employment_status = 'terminated'
            WHERE is_active IS FALSE
              AND employment_status = 'active'
        ");

        // Inverse guard: should not exist in practice, but close any gap where
        // employment_status = 'terminated' but is_active slipped back to true.
        DB::statement("
            UPDATE users
            SET is_active = FALSE
            WHERE employment_status = 'terminated'
              AND is_active IS TRUE
        ");
    }

    public function down(): void
    {
        // Not reversible in a meaningful way — we cannot know which rows were
        // legitimately terminated vs incorrectly backfilled. Down() is a no-op.
        // If a rollback is needed, restore from a database backup.
    }
};

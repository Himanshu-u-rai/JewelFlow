<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reporting spine — Phase 0 (REPORT_EXPORT_IMPLEMENTATION_PLAN.md §0.2 / §4.1, frozen §28).
 *
 * `reports.audit` is the whole-surface owner/manager-only gate for the §28
 * exception set — Operator Performance, Suspicious Activity, and (on top of
 * `dhiran.reports`) Dhiran Forfeiture / Profitability. Those routes are
 * `can:reports.view` today; the gate switch (reports.view → reports.audit)
 * lands in Phase 4 when the reports migrate onto the spine. The permission +
 * per-shop backfill ship here so the gate exists before it is referenced (H-1).
 *
 * Backfill: existing per-shop owner + manager roles. NEW shops are covered
 * automatically by TenantRoleService (owner=all; manager=all-except
 * settings.edit/staff.manage). Staff never receives it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'reports.audit'],
            [
                'display_name' => 'View Audit Reports (Operator / Suspicious Activity / Dhiran)',
                'group'        => 'Reports',
                'updated_at'   => $now,
                'created_at'   => $now,
            ]
        );

        $permId = DB::table('permissions')->where('name', 'reports.audit')->value('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', ['owner', 'manager'])
            ->whereNotNull('shop_id')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('name', 'reports.audit')->value('id');

        if ($permId !== null) {
            DB::table('role_permission')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};

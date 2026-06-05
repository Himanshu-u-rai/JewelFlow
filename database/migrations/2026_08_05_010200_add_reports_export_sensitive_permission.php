<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reporting spine — Phase 0 (REPORT_EXPORT_IMPLEMENTATION_PLAN.md §0.2, frozen §10/§28).
 *
 * `reports.export_sensitive` gates sensitive data (cost/margin, customer PII,
 * operator identity, raw/full dumps) at the column/export layer — viewing a
 * report stays on `reports.view` (Addendum C §28).
 *
 * Backfill follows the per-shop role-grant precedent
 * (2026_06_01_120000_add_returns_view_create_permissions.php): roles are
 * tenant-scoped (`roles.shop_id`) and seeders do NOT run on deploy, so we grant
 * onto every existing owner/manager role here.
 *
 * NEW shops are handled automatically by TenantRoleService::ensureDefaultsForShop
 * (owner syncs ALL permissions; manager syncs all-except settings.edit/staff.manage
 * — both include this new permission). Staff never receives it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'reports.export_sensitive'],
            [
                'display_name' => 'Export Sensitive Columns & Raw Data',
                'group'        => 'Reports',
                'updated_at'   => $now,
                'created_at'   => $now,
            ]
        );

        $permId = DB::table('permissions')->where('name', 'reports.export_sensitive')->value('id');

        // Existing per-shop owner + manager roles only.
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
        $permId = DB::table('permissions')->where('name', 'reports.export_sensitive')->value('id');

        if ($permId !== null) {
            DB::table('role_permission')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};

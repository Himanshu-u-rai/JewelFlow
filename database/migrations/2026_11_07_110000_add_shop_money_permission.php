<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-11-07: staff must not see what the shop earns. "See Shop Money" (reports.money) now guards every
 * rupee total and every report built on money; "View Reports" becomes "Everyday Reports" (the money-free ones).
 *
 * Upgrade keeps every role exactly as capable as before: roles that could view reports also get the money switch.
 * The owner switches it off per role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'reports.money'],
            ['display_name' => 'See Shop Money (sales, earnings, totals, money reports)', 'group' => 'Reports', 'updated_at' => $now, 'created_at' => $now]
        );
        DB::table('permissions')->where('name', 'reports.view')->update(['display_name' => 'Everyday Reports', 'updated_at' => $now]);

        $moneyId = DB::table('permissions')->where('name', 'reports.money')->value('id');
        $roleIds = DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('permissions.name', 'reports.view')
            ->pluck('role_permission.role_id')
            // Owners hold every permission (no owner bypass in Gate::before): never leave one without the new switch.
            ->merge(DB::table('roles')->where('name', 'owner')->pluck('id'))->unique();

        foreach ($roleIds as $roleId) {
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $moneyId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'reports.money')->value('id');
        if ($id !== null) {
            DB::table('role_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        DB::table('permissions')->where('name', 'reports.view')->update(['display_name' => 'View Reports']);
    }
};

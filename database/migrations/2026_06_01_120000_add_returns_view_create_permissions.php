<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-connection of the returns/exchange web surface (RETURNS_SYSTEM_DIAGNOSTIC.md).
 *
 * The web routes gate on returns.view / returns.create, but only returns.approve
 * was ever seeded. This adds the two missing permissions and grants them to
 * existing roles:
 *   - returns.view   → owner, manager, staff (cashiers need to see returns)
 *   - returns.create → owner, manager, staff (cashiers process returns)
 *   - returns.approve already exists (owner + manager) — untouched.
 *
 * New shops are handled by TenantRoleService (owner syncs all; manager all-but-2;
 * staff gets returns.view + returns.create added to its explicit list).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $permissions = [
            'returns.view'   => 'View Returns & Exchanges',
            'returns.create' => 'Create Returns & Exchanges',
        ];

        foreach ($permissions as $name => $display) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['display_name' => $display, 'group' => 'Sales & Customers', 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $permIds = DB::table('permissions')->whereIn('name', array_keys($permissions))->pluck('id');

        // Grant view + create to owner, manager, staff for every existing shop role.
        $roleIds = DB::table('roles')
            ->whereIn('name', ['owner', 'manager', 'staff'])
            ->whereNotNull('shop_id')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permIds as $permId) {
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        $permIds = DB::table('permissions')->whereIn('name', ['returns.view', 'returns.create'])->pluck('id');

        if ($permIds->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $permIds)->delete();
            DB::table('permissions')->whereIn('id', $permIds)->delete();
        }
    }
};

<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Support\TenantContext;

class TenantRoleService
{
    /**
     * Ensure default tenant roles exist for the provided shop and return them by name.
     *
     * @return array<string, \App\Models\Role>
     */
    public function ensureDefaultsForShop(int $shopId): array
    {
        return TenantContext::runFor($shopId, function () {
            $ownerRole = Role::query()->firstOrCreate(
                ['name' => 'owner'],
                [
                    'display_name' => 'Shop Owner',
                    'description' => 'Full access to all features and settings',
                ]
            );

            $managerRole = Role::query()->firstOrCreate(
                ['name' => 'manager'],
                [
                    'display_name' => 'Manager',
                    'description' => 'Can manage inventory, sales, and staff but not settings',
                ]
            );

            $staffRole = Role::query()->firstOrCreate(
                ['name' => 'staff'],
                [
                    'display_name' => 'Staff',
                    'description' => 'Basic access for day-to-day operations',
                ]
            );

            $allPermissions = Permission::query()->pluck('id');
            $ownerRole->permissions()->sync($allPermissions);

            // Manager: every permission EXCEPT settings.edit and staff.manage.
            // Matches the migration's canonical default at 2026_05_08_200000_add_core_permissions.php.
            $managerPermissions = Permission::query()
                ->whereNotIn('name', ['settings.edit', 'staff.manage'])
                ->pluck('id');
            $managerRole->permissions()->sync($managerPermissions);

            // Staff: minimal set for day-to-day operations. Permission keys must exist
            // in the permissions table (see add_core_permissions migration). The
            // previous version referenced 'invoices.view'/'invoices.create' and
            // 'staff.delete' which were never seeded — silently shrinking Staff's set.
            $staffPermissions = Permission::query()->whereIn('name', [
                'inventory.view',
                'sales.view', 'sales.create', 'sales.pos',
                'customers.view', 'customers.create', 'customers.edit',
                'repairs.view', 'repairs.create', 'repairs.edit',
                'cash.view',
                'vendors.view',
                'karigar.view',
                'job_order.view',
                'karigar_invoice.view',
            ])->pluck('id');
            $staffRole->permissions()->sync($staffPermissions);

            return [
                'owner' => $ownerRole,
                'manager' => $managerRole,
                'staff' => $staffRole,
            ];
        });
    }
}

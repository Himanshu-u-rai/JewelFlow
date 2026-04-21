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

            $managerPermissions = Permission::query()
                ->whereNotIn('name', ['settings.edit', 'staff.delete'])
                ->pluck('id');
            $managerRole->permissions()->sync($managerPermissions);

            $staffPermissions = Permission::query()->whereIn('name', [
                'inventory.view',
                'sales.view',
                'sales.create',
                'sales.pos',
                'customers.view',
                'customers.create',
                'invoices.view',
                'invoices.create',
                'repairs.view',
                'repairs.create',
                'repairs.edit',
                'cash.view',
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

<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Shop;
use App\Services\TenantRoleService;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define all permissions grouped by module
        $permissions = [
            // Inventory
            ['name' => 'inventory.view', 'display_name' => 'View Inventory', 'group' => 'inventory'],
            ['name' => 'inventory.create', 'display_name' => 'Create Items', 'group' => 'inventory'],
            ['name' => 'inventory.edit', 'display_name' => 'Edit Items', 'group' => 'inventory'],
            ['name' => 'inventory.delete', 'display_name' => 'Delete Items', 'group' => 'inventory'],
            ['name' => 'inventory.manufacture', 'display_name' => 'Manufacture Items', 'group' => 'inventory'],

            // Sales
            ['name' => 'sales.view', 'display_name' => 'View Sales', 'group' => 'sales'],
            ['name' => 'sales.create', 'display_name' => 'Create Sales', 'group' => 'sales'],
            ['name' => 'sales.pos', 'display_name' => 'Access POS', 'group' => 'sales'],

            // Customers
            ['name' => 'customers.view', 'display_name' => 'View Customers', 'group' => 'customers'],
            ['name' => 'customers.create', 'display_name' => 'Create Customers', 'group' => 'customers'],
            ['name' => 'customers.edit', 'display_name' => 'Edit Customers', 'group' => 'customers'],
            ['name' => 'customers.delete', 'display_name' => 'Delete Customers', 'group' => 'customers'],

            // Invoices
            ['name' => 'invoices.view', 'display_name' => 'View Invoices', 'group' => 'invoices'],
            ['name' => 'invoices.create', 'display_name' => 'Create Invoices', 'group' => 'invoices'],
            ['name' => 'invoices.edit', 'display_name' => 'Edit Invoices', 'group' => 'invoices'],
            ['name' => 'invoices.delete', 'display_name' => 'Delete Invoices', 'group' => 'invoices'],

            // Repairs
            ['name' => 'repairs.view', 'display_name' => 'View Repairs', 'group' => 'repairs'],
            ['name' => 'repairs.create', 'display_name' => 'Create Repairs', 'group' => 'repairs'],
            ['name' => 'repairs.edit', 'display_name' => 'Edit Repairs', 'group' => 'repairs'],
            ['name' => 'repairs.delete', 'display_name' => 'Delete Repairs', 'group' => 'repairs'],

            // Metal Lots
            ['name' => 'metal_lots.view', 'display_name' => 'View Metal Lots', 'group' => 'metal_lots'],
            ['name' => 'metal_lots.create', 'display_name' => 'Create Metal Lots', 'group' => 'metal_lots'],
            ['name' => 'metal_lots.edit', 'display_name' => 'Edit Metal Lots', 'group' => 'metal_lots'],
            ['name' => 'metal_lots.delete', 'display_name' => 'Delete Metal Lots', 'group' => 'metal_lots'],

            // Cash Transactions
            ['name' => 'cash.view', 'display_name' => 'View Cash Transactions', 'group' => 'cash'],
            ['name' => 'cash.create', 'display_name' => 'Create Cash Transactions', 'group' => 'cash'],

            // Reports
            ['name' => 'reports.view', 'display_name' => 'View Reports', 'group' => 'reports'],
            ['name' => 'reports.export', 'display_name' => 'Export Reports', 'group' => 'reports'],
            ['name' => 'reports.daily_closing', 'display_name' => 'Access Daily Closing', 'group' => 'reports'],

            // Audit Log
            ['name' => 'audit.view', 'display_name' => 'View Audit Log', 'group' => 'audit'],

            // Staff Management
            ['name' => 'staff.view', 'display_name' => 'View Staff', 'group' => 'staff'],
            ['name' => 'staff.create', 'display_name' => 'Create Staff', 'group' => 'staff'],
            ['name' => 'staff.edit', 'display_name' => 'Edit Staff', 'group' => 'staff'],
            ['name' => 'staff.delete', 'display_name' => 'Delete Staff', 'group' => 'staff'],

            // Settings
            ['name' => 'settings.view', 'display_name' => 'View Settings', 'group' => 'settings'],
            ['name' => 'settings.edit', 'display_name' => 'Edit Settings', 'group' => 'settings'],

            // Categories
            ['name' => 'categories.view', 'display_name' => 'View Categories', 'group' => 'categories'],
            ['name' => 'categories.create', 'display_name' => 'Create Categories', 'group' => 'categories'],
            ['name' => 'categories.edit', 'display_name' => 'Edit Categories', 'group' => 'categories'],
            ['name' => 'categories.delete', 'display_name' => 'Delete Categories', 'group' => 'categories'],
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name']],
                $permission
            );
        }

        /** @var \App\Services\TenantRoleService $tenantRoleService */
        $tenantRoleService = app(TenantRoleService::class);

        // Tenant roles are shop-scoped and must be provisioned explicitly per shop.
        Shop::query()->pluck('id')->each(function (int $shopId) use ($tenantRoleService) {
            $tenantRoleService->ensureDefaultsForShop($shopId);
        });
    }
}

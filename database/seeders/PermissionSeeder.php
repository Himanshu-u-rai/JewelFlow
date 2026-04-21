<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            // Sales
            ['name' => 'pos.access', 'display_name' => 'Access Point of Sale', 'group' => 'sales'],
            ['name' => 'invoices.view', 'display_name' => 'View Invoices', 'group' => 'sales'],
            ['name' => 'invoices.edit', 'display_name' => 'Edit Invoices', 'group' => 'sales'],

            // Inventory
            ['name' => 'items.view', 'display_name' => 'View Stock & Items', 'group' => 'inventory'],
            ['name' => 'items.create', 'display_name' => 'Add Items', 'group' => 'inventory'],
            ['name' => 'items.edit', 'display_name' => 'Edit Items', 'group' => 'inventory'],
            ['name' => 'items.delete', 'display_name' => 'Delete Items', 'group' => 'inventory'],
            ['name' => 'categories.manage', 'display_name' => 'Manage Categories', 'group' => 'inventory'],
            ['name' => 'customers.view', 'display_name' => 'View Customers', 'group' => 'inventory'],
            ['name' => 'customers.create', 'display_name' => 'Add Customers', 'group' => 'inventory'],
            ['name' => 'customers.edit', 'display_name' => 'Edit Customers', 'group' => 'inventory'],

            // Retail
            ['name' => 'vendors.manage', 'display_name' => 'Manage Vendors', 'group' => 'retail'],
            ['name' => 'schemes.manage', 'display_name' => 'Manage Schemes', 'group' => 'retail'],
            ['name' => 'installments.manage', 'display_name' => 'Manage EMI / Installments', 'group' => 'retail'],
            ['name' => 'repairs.manage', 'display_name' => 'Manage Repairs', 'group' => 'retail'],
            ['name' => 'loyalty.manage', 'display_name' => 'Manage Loyalty Points', 'group' => 'retail'],

            // Reports
            ['name' => 'reports.view', 'display_name' => 'View Reports', 'group' => 'reports'],
            ['name' => 'cashbook.view', 'display_name' => 'View Cash Book', 'group' => 'reports'],
            ['name' => 'exports.access', 'display_name' => 'Export Data', 'group' => 'reports'],

            // System
            ['name' => 'imports.access', 'display_name' => 'Bulk Imports', 'group' => 'system'],
            ['name' => 'settings.view', 'display_name' => 'View Settings', 'group' => 'system'],
        ];

        $now = Carbon::now();

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                [
                    'display_name' => $permission['display_name'],
                    'group' => $permission['group'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}

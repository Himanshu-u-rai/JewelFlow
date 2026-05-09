<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $permissions = [
            // ── Inventory ──────────────────────────────────────────────────────────
            ['name' => 'inventory.view',        'display_name' => 'View Inventory',            'group' => 'Inventory'],
            ['name' => 'inventory.create',      'display_name' => 'Add / Register Items',      'group' => 'Inventory'],
            ['name' => 'inventory.edit',        'display_name' => 'Edit Items',                'group' => 'Inventory'],
            ['name' => 'inventory.delete',      'display_name' => 'Delete Items',              'group' => 'Inventory'],

            // ── Sales & POS ────────────────────────────────────────────────────────
            ['name' => 'sales.view',            'display_name' => 'View Sales & Invoices',     'group' => 'Sales & POS'],
            ['name' => 'sales.create',          'display_name' => 'Create Sales / Invoices',   'group' => 'Sales & POS'],
            ['name' => 'sales.pos',             'display_name' => 'Access POS',                'group' => 'Sales & POS'],
            ['name' => 'sales.void',            'display_name' => 'Void / Reverse Bills',      'group' => 'Sales & POS'],

            // ── Customers ──────────────────────────────────────────────────────────
            ['name' => 'customers.view',        'display_name' => 'View Customers',            'group' => 'Customers'],
            ['name' => 'customers.create',      'display_name' => 'Add Customers',             'group' => 'Customers'],
            ['name' => 'customers.edit',        'display_name' => 'Edit Customers',            'group' => 'Customers'],
            ['name' => 'customers.delete',      'display_name' => 'Delete Customers',          'group' => 'Customers'],

            // ── Repairs ───────────────────────────────────────────────────────────
            ['name' => 'repairs.view',          'display_name' => 'View Repairs',              'group' => 'Repairs'],
            ['name' => 'repairs.create',        'display_name' => 'Create Repairs',            'group' => 'Repairs'],
            ['name' => 'repairs.edit',          'display_name' => 'Edit & Deliver Repairs',    'group' => 'Repairs'],
            ['name' => 'repairs.delete',        'display_name' => 'Delete Repairs',            'group' => 'Repairs'],

            // ── Cash & Payments ────────────────────────────────────────────────────
            ['name' => 'cash.view',             'display_name' => 'View Cash Transactions',    'group' => 'Cash & Payments'],
            ['name' => 'cash.create',           'display_name' => 'Record Cash Transactions',  'group' => 'Cash & Payments'],

            // ── Vendors ───────────────────────────────────────────────────────────
            ['name' => 'vendors.view',          'display_name' => 'View Vendors / Suppliers',  'group' => 'Vendors'],
            ['name' => 'vendors.manage',        'display_name' => 'Add / Edit / Delete Vendors','group' => 'Vendors'],
            ['name' => 'vendors.ledger',        'display_name' => 'View Vendor Ledger',        'group' => 'Vendors'],

            // ── Catalog & Pricing ──────────────────────────────────────────────────
            ['name' => 'catalog.manage',        'display_name' => 'Manage Catalog & Collections','group' => 'Catalog & Pricing'],
            ['name' => 'pricing.update',        'display_name' => 'Update Daily Gold / Silver Rates','group' => 'Catalog & Pricing'],

            // ── Reports ───────────────────────────────────────────────────────────
            ['name' => 'reports.view',          'display_name' => 'View Reports',              'group' => 'Reports'],
            ['name' => 'reports.export',        'display_name' => 'Export Data',               'group' => 'Reports'],
            ['name' => 'reports.daily_closing', 'display_name' => 'Access Daily Closing',      'group' => 'Reports'],

            // ── Staff & Settings ───────────────────────────────────────────────────
            ['name' => 'staff.view',            'display_name' => 'View Staff Members',        'group' => 'Staff & Settings'],
            ['name' => 'staff.manage',          'display_name' => 'Add / Edit / Remove Staff', 'group' => 'Staff & Settings'],
            ['name' => 'settings.view',         'display_name' => 'View Settings',             'group' => 'Staff & Settings'],
            ['name' => 'settings.edit',         'display_name' => 'Edit Settings & Roles',     'group' => 'Staff & Settings'],

            // ── Job Work (Retailer edition) ────────────────────────────────────────
            ['name' => 'vault.view',            'display_name' => 'View Bullion Vault',        'group' => 'Job Work'],
            ['name' => 'vault.manage',          'display_name' => 'Add Bullion Lots to Vault', 'group' => 'Job Work'],
            ['name' => 'karigar.view',          'display_name' => 'View Karigars',             'group' => 'Job Work'],
            ['name' => 'karigar.manage',        'display_name' => 'Add / Edit Karigars',       'group' => 'Job Work'],
            ['name' => 'job_order.view',        'display_name' => 'View Job Orders',           'group' => 'Job Work'],
            ['name' => 'job_order.manage',      'display_name' => 'Issue / Receive / Cancel Job Orders', 'group' => 'Job Work'],
            ['name' => 'karigar_invoice.view',  'display_name' => 'View Karigar Invoices',     'group' => 'Job Work'],
            ['name' => 'karigar_invoice.manage','display_name' => 'Create / Edit Karigar Invoices & Payments', 'group' => 'Job Work'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                [
                    'display_name' => $permission['display_name'],
                    'group'        => $permission['group'],
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]
            );
        }

        // ── Assign sensible defaults to existing roles ─────────────────────────────
        // Owner: all permissions (already gets everything via the seeder loop below)
        // Manager: everything except settings.edit and staff.manage
        // Staff: view + POS + repairs + customers create
        $allPerms    = DB::table('permissions')->pluck('id', 'name');

        $ownerPerms   = $allPerms->values()->all();

        $managerPerms = $allPerms->filter(fn($id, $name) => !in_array($name, [
            'settings.edit',
            'staff.manage',
        ]))->values()->all();

        $staffPerms   = $allPerms->filter(fn($id, $name) => in_array($name, [
            'inventory.view',
            'sales.view', 'sales.create', 'sales.pos',
            'customers.view', 'customers.create', 'customers.edit',
            'repairs.view', 'repairs.create', 'repairs.edit',
            'cash.view',
            'vendors.view',
            'karigar.view',
            'job_order.view',
            'karigar_invoice.view',
            'vault.view',
            // Dhiran — staff can only view
            'dhiran.view',
        ]))->values()->all();

        $roles = DB::table('roles')->get(['id', 'name']);

        foreach ($roles as $role) {
            $permsToAssign = match ($role->name) {
                'owner'   => $ownerPerms,
                'manager' => $managerPerms,
                'staff'   => $staffPerms,
                default   => [],
            };

            foreach ($permsToAssign as $permId) {
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        $names = [
            'inventory.view','inventory.create','inventory.edit','inventory.delete',
            'sales.view','sales.create','sales.pos','sales.void',
            'customers.view','customers.create','customers.edit','customers.delete',
            'repairs.view','repairs.create','repairs.edit','repairs.delete',
            'cash.view','cash.create',
            'vendors.view','vendors.manage','vendors.ledger',
            'catalog.manage','pricing.update',
            'reports.view','reports.export','reports.daily_closing',
            'staff.view','staff.manage','settings.view','settings.edit',
            'vault.view','vault.manage',
            'karigar.view','karigar.manage',
            'job_order.view','job_order.manage',
            'karigar_invoice.view','karigar_invoice.manage',
        ];

        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('name', $names)->delete();
    }
};

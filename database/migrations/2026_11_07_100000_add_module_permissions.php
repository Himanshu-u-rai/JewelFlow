<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-11-07: modules that rode on someone else's permission get their own, so the owner can grant
 * them separately (e.g. take scheme payments without creating invoices, or adjust loyalty points without editing
 * customer details).
 *
 * Upgrade keeps every existing role exactly as capable as before: each new permission is granted to every role that
 * already held the permission it replaces. The owner can then switch things off per role.
 *
 * Dhiran is not touched.
 */
return new class extends Migration
{
    /** name => [display name, group, permissions it replaces] */
    private const NEW = [
        'schemes.view'          => ['View Schemes & Enrolments', 'Schemes', ['catalog.manage', 'sales.view']],
        'schemes.manage'        => ['Create / Edit / Delete Schemes', 'Schemes', ['catalog.manage']],
        'schemes.enroll'        => ['Enrol Customers & Collect Scheme Payments', 'Schemes', ['sales.create']],
        'schemes.cancel'        => ['Cancel Scheme Enrolments', 'Schemes', ['sales.void']],
        'installments.view'     => ['View EMI Plans', 'EMI / Installments', ['sales.view']],
        'installments.create'   => ['Create EMI Plans', 'EMI / Installments', ['sales.create']],
        'installments.collect'  => ['Collect EMI Payments', 'EMI / Installments', ['sales.create']],
        'installments.default'  => ['Mark EMI Plans Defaulted', 'EMI / Installments', ['sales.void']],
        'quick_bills.view'      => ['View Quick Bills', 'Quick Bills', ['sales.view']],
        'quick_bills.create'    => ['Create / Edit Quick Bills', 'Quick Bills', ['sales.create']],
        'quick_bills.void'      => ['Void Quick Bills', 'Quick Bills', ['sales.void']],
        'loyalty.adjust'        => ['Adjust Loyalty Points', 'Customers', ['customers.edit']],
        'categories.manage'     => ['Manage Categories, Sub-categories & Products', 'Inventory', ['catalog.manage']],
        'audit.view'            => ['View Audit Log', 'Staff & Settings', ['settings.view']],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach (self::NEW as $name => [$display, $group, $sources]) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['display_name' => $display, 'group' => $group, 'updated_at' => $now, 'created_at' => $now]
            );
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');

            $roleIds = DB::table('role_permission')
                ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
                ->whereIn('permissions.name', $sources)
                ->distinct()
                ->pluck('role_permission.role_id');

            foreach ($roleIds as $roleId) {
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::NEW))->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};

<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'billing.view'],
            [
                'display_name' => 'View Billing & Invoices',
                'group'        => 'Staff & Settings',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );

        $permId = DB::table('permissions')->where('name', 'billing.view')->value('id');

        // Assign only to owner roles — billing is not delegated to managers by default.
        $ownerRoleIds = DB::table('roles')
            ->where('name', 'owner')
            ->whereNotNull('shop_id')
            ->pluck('id');

        foreach ($ownerRoleIds as $roleId) {
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('name', 'billing.view')->value('id');

        if ($permId) {
            DB::table('role_permission')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};

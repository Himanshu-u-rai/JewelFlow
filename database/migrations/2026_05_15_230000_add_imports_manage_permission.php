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
            ['name' => 'imports.manage'],
            [
                'display_name' => 'Manage Bulk Imports',
                'group'        => 'Reports',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );

        $permId = DB::table('permissions')->where('name', 'imports.manage')->value('id');

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
        $permId = DB::table('permissions')->where('name', 'imports.manage')->value('id');

        if ($permId) {
            DB::table('role_permission')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};

<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $vaultViewId  = DB::table('permissions')->where('name', 'vault.view')->value('id');
        $dhiranViewId = DB::table('permissions')->where('name', 'dhiran.view')->value('id');

        if (! $vaultViewId || ! $dhiranViewId) {
            throw new RuntimeException(
                'Cannot backfill: vault.view or dhiran.view permission not found. ' .
                'Ensure 2026_05_08_200000_add_core_permissions has run first.'
            );
        }

        $staffRoleIds = DB::table('roles')
            ->where('name', 'staff')
            ->whereNotNull('shop_id')
            ->pluck('id');

        foreach ($staffRoleIds as $roleId) {
            foreach ([$vaultViewId, $dhiranViewId] as $permId) {
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        // Intentional no-op. This is a data correction backfill, not a schema
        // change. Removing these permissions on rollback would silently deny
        // staff users access they legitimately have. Manual intervention only.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Historical Sales — Batch 1: permission definitions.
 *
 * Definitions only. Batch 1 ships no import or reporting screen, but the release
 * gate requires permission enforcement to exist rather than be retrofitted, and
 * the project convention is that a permission must be defined by migration
 * before anything can gate on it.
 *
 * Granted to `owner` ONLY. Importing a shop's entire trading history is an
 * owner-level act; a manager gets it only when the owner explicitly assigns it.
 * This deliberately differs from the imports.manage precedent, which granted
 * owner + manager.
 */
return new class extends Migration
{
    private array $permissions = [
        'historical.view'    => 'View Historical Sales',
        'historical.import'  => 'Import Historical Sales',
        'historical.publish' => 'Publish Historical Sales',
    ];

    public function up(): void
    {
        $now = now();

        $ownerRoleIds = DB::table('roles')
            ->where('name', 'owner')
            ->whereNotNull('shop_id')
            ->pluck('id');

        foreach ($this->permissions as $name => $displayName) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'display_name' => $displayName,
                    'group'        => 'Reports',
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]
            );

            $permissionId = DB::table('permissions')->where('name', $name)->value('id');

            foreach ($ownerRoleIds as $roleId) {
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');

        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

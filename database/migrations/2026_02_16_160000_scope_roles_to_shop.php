<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (!Schema::hasColumn('roles', 'shop_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->cascadeOnDelete();
            });
        }

        // Legacy schema had globally unique role names; drop it before cloning roles per shop.
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_name_unique');
        DB::statement('DROP INDEX IF EXISTS roles_name_unique');

        $legacyRoles = DB::table('roles')->whereNull('shop_id')->get();
        if ($legacyRoles->isNotEmpty()) {
            $legacyRoleIds = $legacyRoles->pluck('id')->all();
            $shops = DB::table('shops')->pluck('id')->all();
            $rolePermissions = DB::table('role_permission')
                ->whereIn('role_id', $legacyRoleIds)
                ->get()
                ->groupBy('role_id');

            $map = [];
            $now = now();

            foreach ($shops as $shopId) {
                foreach ($legacyRoles as $role) {
                    $existingRoleId = DB::table('roles')
                        ->where('shop_id', $shopId)
                        ->where('name', $role->name)
                        ->value('id');

                    if (!$existingRoleId) {
                        $existingRoleId = DB::table('roles')->insertGetId([
                            'shop_id' => $shopId,
                            'name' => $role->name,
                            'display_name' => $role->display_name,
                            'description' => $role->description,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $map[$shopId][$role->id] = $existingRoleId;

                    foreach (($rolePermissions[$role->id] ?? collect()) as $permissionRow) {
                        DB::table('role_permission')->updateOrInsert([
                            'role_id' => $existingRoleId,
                            'permission_id' => $permissionRow->permission_id,
                        ], [
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]);
                    }
                }
            }

            $users = DB::table('users')
                ->whereNotNull('shop_id')
                ->whereNotNull('role_id')
                ->whereIn('role_id', $legacyRoleIds)
                ->get(['id', 'shop_id', 'role_id']);

            foreach ($users as $user) {
                $newRoleId = $map[$user->shop_id][$user->role_id] ?? null;
                if ($newRoleId) {
                    DB::table('users')->where('id', $user->id)->update(['role_id' => $newRoleId]);
                }
            }

            DB::table('role_permission')->whereIn('role_id', $legacyRoleIds)->delete();
            DB::table('roles')->whereIn('id', $legacyRoleIds)->delete();
        }

        $nullRoles = DB::table('roles')->whereNull('shop_id')->count();
        if ($nullRoles > 0) {
            throw new RuntimeException("Cannot enforce tenant roles: {$nullRoles} role rows still have NULL shop_id.");
        }

        DB::statement('ALTER TABLE roles ALTER COLUMN shop_id SET NOT NULL');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS roles_shop_id_name_unique ON roles (shop_id, name)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS roles_id_shop_id_unique ON roles (id, shop_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS roles_shop_id_index ON roles (shop_id)');

        // Enforce that a user's assigned role always belongs to the same shop.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_id_foreign');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_id_shop_id_foreign');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_id_shop_id_foreign
            FOREIGN KEY (role_id, shop_id) REFERENCES roles (id, shop_id) ON UPDATE CASCADE ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS roles_shop_id_name_unique');
        DB::statement('DROP INDEX IF EXISTS roles_id_shop_id_unique');
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_name_unique');
        DB::statement('DROP INDEX IF EXISTS roles_name_unique');
        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_name_unique UNIQUE (name)');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_id_shop_id_foreign');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_id_foreign');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_id_foreign
            FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE SET NULL');

        if (Schema::hasColumn('roles', 'shop_id')) {
            DB::statement('ALTER TABLE roles ALTER COLUMN shop_id DROP NOT NULL');
        }
    }
};

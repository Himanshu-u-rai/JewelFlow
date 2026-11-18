<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Financial-year project (owner-approved 2026-11-16): an entry dated in a FINISHED financial year, while the books
 * are not locked for that date, is a back-dating. It needs a written reason and either the shop owner or this
 * permission ("Post entries into a finished financial year"). Owners and managers get it; staff/cashiers do not.
 * Owners hold every permission (no owner bypass in Gate::before), so every owner role gets it outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'books.backdate'],
            ['display_name' => 'Post entries into a finished financial year (needs a reason)', 'group' => 'Staff & Settings', 'updated_at' => $now, 'created_at' => $now]
        );

        $id = DB::table('permissions')->where('name', 'books.backdate')->value('id');
        foreach (DB::table('roles')->whereIn('name', ['owner', 'manager'])->pluck('id') as $roleId) {
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $id],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'books.backdate')->value('id');
        if ($id !== null) {
            DB::table('role_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        // ── Schema: new approval columns on shop_preferences ──────────────
        Schema::table('shop_preferences', function (Blueprint $table) {
            // null = approval never triggered by amount
            $table->decimal('approval_threshold_amount', 12, 2)
                ->nullable()
                ->default(null)
                ->after('return_settlement_mode');

            // melt / write-off require approval when true
            $table->boolean('approval_required_for_sensitive_dispositions')
                ->default(false)
                ->after('approval_threshold_amount');
        });

        // ── Seed: returns.approve permission ──────────────────────────────
        $now = Carbon::now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'returns.approve'],
            [
                'display_name' => 'Approve Returns',
                'group'        => 'Sales & POS',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );

        // ── Grant returns.approve to owner and manager roles ──────────────
        $permission = DB::table('permissions')->where('name', 'returns.approve')->first();
        if (!$permission) {
            return;
        }

        $ownerRole   = DB::table('roles')->where('name', 'owner')->first();
        $managerRole = DB::table('roles')->where('name', 'manager')->first();

        foreach (array_filter([$ownerRole, $managerRole]) as $role) {
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $permission->id],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        // Remove role grants first
        $permission = DB::table('permissions')->where('name', 'returns.approve')->first();
        if ($permission) {
            DB::table('role_permission')->where('permission_id', $permission->id)->delete();
            DB::table('permissions')->where('name', 'returns.approve')->delete();
        }

        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'approval_threshold_amount',
                'approval_required_for_sensitive_dispositions',
            ]);
        });
    }
};

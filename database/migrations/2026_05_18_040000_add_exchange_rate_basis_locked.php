<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase D — Exchange Valuation Hardening.
     *
     * 1. Adds `exchange_rate_basis_locked` to shop_preferences: when true,
     *    staff cannot deviate from the shop's configured default basis.
     * 2. Inserts the `exchanges.override_rate` permission and grants it to
     *    the owner role only (tighter than returns.approve which also grants
     *    to manager).
     */
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->boolean('exchange_rate_basis_locked')->default(false)
                  ->after('gold_rate_basis_for_exchange');
        });

        DB::table('permissions')->insert([
            'name'         => 'exchanges.override_rate',
            'display_name' => 'Override Exchange Rate Basis',
            'group'        => 'Sales & POS',
            'description'  => 'Allows changing the gold rate basis (sale-day vs today) during exchanges',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Grant to owner role only (not manager — tighter than returns.approve)
        $ownerRole = DB::table('roles')->where('name', 'owner')->first();
        $perm      = DB::table('permissions')->where('name', 'exchanges.override_rate')->first();
        if ($ownerRole && $perm) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id'       => $ownerRole->id,
                'permission_id' => $perm->id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn('exchange_rate_basis_locked');
        });

        $perm = DB::table('permissions')->where('name', 'exchanges.override_rate')->first();
        if ($perm) {
            DB::table('role_permissions')->where('permission_id', $perm->id)->delete();
            DB::table('permissions')->where('id', $perm->id)->delete();
        }
    }
};

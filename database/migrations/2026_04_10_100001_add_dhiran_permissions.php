<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['name' => 'dhiran.view', 'display_name' => 'View Gold Loans', 'group' => 'dhiran'],
            ['name' => 'dhiran.create', 'display_name' => 'Create Gold Loans', 'group' => 'dhiran'],
            ['name' => 'dhiran.pay', 'display_name' => 'Record Loan Payments', 'group' => 'dhiran'],
            ['name' => 'dhiran.release', 'display_name' => 'Release Pledged Items', 'group' => 'dhiran'],
            ['name' => 'dhiran.renew', 'display_name' => 'Renew Gold Loans', 'group' => 'dhiran'],
            ['name' => 'dhiran.forfeit', 'display_name' => 'Forfeit Gold Loans', 'group' => 'dhiran'],
            ['name' => 'dhiran.settings', 'display_name' => 'Manage Dhiran Settings', 'group' => 'dhiran'],
            ['name' => 'dhiran.reports', 'display_name' => 'View Dhiran Reports', 'group' => 'dhiran'],
        ];

        $now = Carbon::now();

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                [
                    'display_name' => $permission['display_name'],
                    'group' => $permission['group'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [
            'dhiran.view',
            'dhiran.create',
            'dhiran.pay',
            'dhiran.release',
            'dhiran.renew',
            'dhiran.forfeit',
            'dhiran.settings',
            'dhiran.reports',
        ])->delete();
    }
};

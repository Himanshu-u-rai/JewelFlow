<?php

namespace Database\Seeders;

use App\Models\Platform\PlatformAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PlatformSuperAdminSeeder extends Seeder
{
    /**
     * Self-service onboarding (paid AND trial) records its actor as a platform
     * super admin — SubscriptionPaymentService::systemAdmin(). With no super
     * admin row, every payment callback aborts with "Platform configuration
     * incomplete" and the owner is bounced back to the payment page. A fresh
     * local/CI database has no platform admins, so onboarding is impossible to
     * complete there until one exists.
     *
     * Production seeds its real super admin out of band, never from here with a
     * known password — so this is a no-op in production.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        PlatformAdmin::firstOrCreate(
            ['email' => 'sunnybunny966@gmail.com'],
            [
                'first_name' => 'Local',
                'last_name' => 'Admin',
                'name' => 'Local Super Admin',
                'mobile_number' => '9000000001',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'is_active' => DB::raw('true'), // pgsql boolean — PHP true binds as integer
            ]
        );
    }
}

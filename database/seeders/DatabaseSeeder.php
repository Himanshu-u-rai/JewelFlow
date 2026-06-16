<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Foundation data the app needs to function. No demo users/shops — real
        // shops are created through onboarding. (A leftover scaffolding "Test User"
        // insert lived here and broke production seeding on PostgreSQL.)
        $this->call(PermissionSeeder::class);
        $this->call(PlatformProductSeeder::class);
        $this->call(PlanSeeder::class);
        $this->call(CustomerSeeder::class);
    }
}

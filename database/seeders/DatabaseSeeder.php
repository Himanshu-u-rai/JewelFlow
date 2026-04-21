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
        // User::factory(10)->create();

        User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'mobile_number' => '9876543210',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'is_active' => true,
        ]);

        $this->call(PermissionSeeder::class);
        $this->call(PlanSeeder::class);
        $this->call(CustomerSeeder::class);
    }
}

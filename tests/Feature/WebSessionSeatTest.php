<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebSessionSeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_always_allowed_regardless_of_seat_count(): void
    {
        $tenant = $this->createTenant(staffLimit: 1);

        $this->webLogin($tenant['manager'])->assertRedirect();
        $this->assertAuthenticated();
        $this->post('/logout');

        $this->webLogin($tenant['owner'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_staff_login_succeeds_when_seat_is_free(): void
    {
        $tenant = $this->createTenant(staffLimit: 2);

        $this->webLogin($tenant['manager'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_limit_reached_blocks_new_non_owner_login(): void
    {
        $tenant = $this->createTenant(staffLimit: 1);

        // Seed a live session for the manager.
        $this->seedSession($tenant['manager']);

        $response = $this->webLogin($tenant['staff']);

        $response->assertRedirect('/login');
        $this->assertGuest();
        $this->assertStringContainsString(
            'web seats are in use',
            session()->get('errors')?->first('mobile_number') ?? ''
        );
    }

    public function test_same_user_relogin_is_always_allowed_at_cap(): void
    {
        $tenant = $this->createTenant(staffLimit: 1);

        $this->seedSession($tenant['manager']);

        // Same user logging in again should succeed even though cap is reached.
        $this->webLogin($tenant['manager'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_expired_session_does_not_consume_a_seat(): void
    {
        $tenant = $this->createTenant(staffLimit: 1);

        // Seed a session whose last_activity is older than session.lifetime.
        $lifetimeSeconds = config('session.lifetime', 120) * 60;
        $this->seedSession($tenant['manager'], lastActivity: now()->timestamp - $lifetimeSeconds - 1);

        // Slot should now be free.
        $this->webLogin($tenant['staff'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_plan_with_unlimited_seats_never_blocks(): void
    {
        $tenant = $this->createTenant(staffLimit: null); // null → unlimited

        foreach (range(1, 5) as $i) {
            $extra = $this->createUser($tenant['shop'], $tenant['staffRole']);
            $this->seedSession($extra);
        }

        $this->webLogin($tenant['staff'])->assertRedirect();
        $this->assertAuthenticated();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function webLogin(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->post('/login', [
            'mobile_number' => $user->mobile_number,
            'password'      => 'password',
        ]);
    }

    private function seedSession(User $user, ?int $lastActivity = null): void
    {
        DB::table('sessions')->insert([
            'id'            => 'test-session-' . $user->id . '-' . uniqid(),
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'test',
            'payload'       => base64_encode('{}'),
            'last_activity' => $lastActivity ?? now()->timestamp,
        ]);
    }

    private function createTenant(?int $staffLimit): array
    {
        $admin = PlatformAdmin::create([
            'first_name'    => 'Platform',
            'last_name'     => 'Admin',
            'name'          => 'Platform Admin',
            'email'         => 'admin' . fake()->unique()->numberBetween(1000, 999999) . '@example.com',
            'mobile_number' => fake()->unique()->numerify('9#########'),
            'password'      => Hash::make('password'),
            'role'          => 'super_admin',
            'is_active'     => true,
        ]);

        $features = $staffLimit !== null ? ['staff_limit' => $staffLimit] : [];

        $plan = Plan::create([
            'code'                          => 'web-seat-' . uniqid(),
            'name'                          => 'Web Seat Plan',
            'price_monthly'                 => 999,
            'grace_days'                    => 5,
            'downgrade_to_read_only_on_due' => true,
            'is_active'                     => true,
            'features'                      => $features,
        ]);

        $shop = Shop::create([
            'name'              => 'Web Seat Shop',
            'shop_type'         => 'retailer',
            'phone'             => fake()->unique()->numerify('9#########'),
            'owner_first_name'  => 'Owner',
            'owner_last_name'   => 'Test',
            'owner_mobile'      => fake()->unique()->numerify('9#########'),
            'gst_rate'          => 3.00,
            'access_mode'       => 'active',
            'is_active'         => true,
        ]);

        ShopSubscription::create([
            'shop_id'             => $shop->id,
            'plan_id'             => $plan->id,
            'status'              => 'active',
            'starts_at'           => now()->subDay()->toDateString(),
            'ends_at'             => now()->addMonth()->toDateString(),
            'grace_ends_at'       => now()->addDays(37)->toDateString(),
            'updated_by_admin_id' => $admin->id,
        ]);

        $ownerRole   = $this->makeRole($shop, 'owner', 'Owner');
        $managerRole = $this->makeRole($shop, 'manager', 'Manager');
        $staffRole   = $this->makeRole($shop, 'staff', 'Staff');

        return [
            'shop'        => $shop,
            'plan'        => $plan,
            'ownerRole'   => $ownerRole,
            'managerRole' => $managerRole,
            'staffRole'   => $staffRole,
            'owner'       => $this->createUser($shop, $ownerRole),
            'manager'     => $this->createUser($shop, $managerRole),
            'staff'       => $this->createUser($shop, $staffRole),
        ];
    }

    private function makeRole(Shop $shop, string $name, string $display): Role
    {
        $role = new Role();
        $attrs = ['name' => $name, 'display_name' => $display];
        if (Schema::hasColumn('roles', 'shop_id')) {
            $attrs['shop_id'] = $shop->id;
        }
        $role->forceFill($attrs)->save();
        return $role;
    }

    private function createUser(Shop $shop, Role $role): User
    {
        return User::factory()->create([
            'shop_id'       => $shop->id,
            'role_id'       => $role->id,
            'mobile_number' => fake()->unique()->numerify('9#########'),
            'password'      => Hash::make('password'),
            'is_active'     => true,
        ]);
    }
}

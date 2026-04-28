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

class MobileAuthSessionLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_and_staff_consume_seats_while_owner_is_exempt(): void
    {
        $tenant = $this->createMobileTenant(staffLimit: 2);

        $this->login($tenant['manager'])->assertOk();
        $this->login($tenant['staff'])->assertOk();
        $this->login($tenant['owner'])->assertOk();

        $this->assertSame(2, $this->activeNonOwnerMobileUsers($tenant['shop']));
    }

    public function test_limit_reached_blocks_new_non_owner_login(): void
    {
        $tenant = $this->createMobileTenant(staffLimit: 1);
        $secondStaff = $this->createUser($tenant['shop'], $tenant['staffRole']);

        $this->login($tenant['manager'])->assertOk();

        $this->login($secondStaff)
            ->assertStatus(403)
            ->assertJson([
                'code' => 'mobile_session_limit_reached',
                'message' => 'Mobile session limit reached for this shop plan.',
                'session_limit' => 1,
                'active_sessions' => 1,
                'seat_scope' => 'non_owner',
            ]);
    }

    public function test_same_user_relogin_is_allowed_at_cap_and_replaces_token(): void
    {
        $tenant = $this->createMobileTenant(staffLimit: 1);

        $first = $this->login($tenant['manager'])->assertOk()->json('token');
        $second = $this->login($tenant['manager'])->assertOk()->json('token');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $this->mobileTokenCount($tenant['manager']));
    }

    public function test_stale_session_older_than_24_hours_does_not_count(): void
    {
        $tenant = $this->createMobileTenant(staffLimit: 1);

        $this->login($tenant['manager'])->assertOk();

        DB::table('personal_access_tokens')
            ->where('tokenable_id', $tenant['manager']->id)
            ->where('tokenable_type', User::class)
            ->where('name', 'mobile-app')
            ->update([
                'last_used_at' => null,
                'created_at' => now()->subHours(25),
                'updated_at' => now()->subHours(25),
            ]);

        $this->login($tenant['staff'])->assertOk();

        $this->assertSame(0, $this->mobileTokenCount($tenant['manager']));
        $this->assertSame(1, $this->mobileTokenCount($tenant['staff']));
    }

    public function test_plan_reduction_keeps_existing_sessions_but_blocks_new_seat_login(): void
    {
        $tenant = $this->createMobileTenant(staffLimit: 2);
        $extraStaff = $this->createUser($tenant['shop'], $tenant['staffRole']);

        $this->login($tenant['manager'])->assertOk();
        $this->login($tenant['staff'])->assertOk();

        $tenant['plan']->forceFill([
            'features' => ['staff_limit' => 1],
        ])->save();

        $this->login($extraStaff)
            ->assertStatus(403)
            ->assertJsonPath('code', 'mobile_session_limit_reached')
            ->assertJsonPath('session_limit', 1)
            ->assertJsonPath('active_sessions', 2);

        $this->assertSame(1, $this->mobileTokenCount($tenant['manager']));
        $this->assertSame(1, $this->mobileTokenCount($tenant['staff']));
        $this->assertSame(0, $this->mobileTokenCount($extraStaff));
    }

    private function login(User $user)
    {
        return $this->postJson('/api/mobile/auth/login', [
            'mobile_number' => $user->mobile_number,
            'password' => 'password',
        ]);
    }

    /**
     * @return array{
     *     shop: Shop,
     *     plan: Plan,
     *     ownerRole: Role,
     *     managerRole: Role,
     *     staffRole: Role,
     *     owner: User,
     *     manager: User,
     *     staff: User
     * }
     */
    private function createMobileTenant(int $staffLimit): array
    {
        $admin = PlatformAdmin::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'name' => 'Platform Admin',
            'email' => 'admin' . fake()->unique()->numberBetween(1000, 999999) . '@example.com',
            'mobile_number' => fake()->unique()->numerify('9#########'),
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'code' => 'mobile-seat-' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Mobile Seat Plan',
            'price_monthly' => 999,
            'grace_days' => 5,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
            'features' => ['staff_limit' => $staffLimit],
        ]);

        $shop = Shop::create([
            'name' => 'Mobile Seat Shop',
            'shop_type' => 'manufacturer',
            'phone' => '9000000000',
            'owner_first_name' => 'Owner',
            'owner_last_name' => 'Test',
            'owner_mobile' => fake()->unique()->numerify('9#########'),
            'gst_rate' => 3.00,
            'wastage_recovery_percent' => 100.00,
            'access_mode' => 'active',
            'is_active' => true,
        ]);

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'grace_ends_at' => now()->addDays(37)->toDateString(),
            'updated_by_admin_id' => $admin->id,
        ]);

        $ownerRole = $this->createRole($shop, 'owner', 'Owner');
        $managerRole = $this->createRole($shop, 'manager', 'Manager');
        $staffRole = $this->createRole($shop, 'staff', 'Staff');

        return [
            'shop' => $shop,
            'plan' => $plan,
            'ownerRole' => $ownerRole,
            'managerRole' => $managerRole,
            'staffRole' => $staffRole,
            'owner' => $this->createUser($shop, $ownerRole),
            'manager' => $this->createUser($shop, $managerRole),
            'staff' => $this->createUser($shop, $staffRole),
        ];
    }

    private function createRole(Shop $shop, string $name, string $displayName): Role
    {
        $attributes = [
            'name' => $name,
            'display_name' => $displayName,
        ];

        if (Schema::hasColumn('roles', 'shop_id')) {
            $attributes['shop_id'] = $shop->id;
        }

        $role = new Role();
        $role->forceFill($attributes);
        $role->save();

        return $role;
    }

    private function createUser(Shop $shop, Role $role): User
    {
        return User::factory()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'mobile_number' => fake()->unique()->numerify('9#########'),
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function mobileTokenCount(User $user): int
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', User::class)
            ->where('name', 'mobile-app')
            ->count();
    }

    private function activeNonOwnerMobileUsers(Shop $shop): int
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join('personal_access_tokens', 'personal_access_tokens.tokenable_id', '=', 'users.id')
            ->where('users.shop_id', $shop->id)
            ->where('roles.name', '!=', 'owner')
            ->where('personal_access_tokens.tokenable_type', User::class)
            ->where('personal_access_tokens.name', 'mobile-app')
            ->distinct('users.id')
            ->count('users.id');
    }
}

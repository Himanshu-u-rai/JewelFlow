<?php

namespace Tests\Feature\Staff;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Staff / Roles / Permissions — feature / security (Module 5). Closes the gaps
 * the existing suites leave open: the HTTP staff-create provisioning, the
 * shop_id / role_id forge surface, cross-shop staff isolation, the manager
 * (no staff.manage) boundary, and device-session scoping. The owner-protection
 * + cross-shop-reactivate + permission-gate cases are already covered by
 * StaffTerminateRecoverTest + RolePermissionEnforcementTest and are not repeated.
 */
class StaffManagementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    /** A tenant with owner + provisioned default roles. @return array{0:User,1:Shop,2:array} */
    private function tenant(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $roles = TenantContext::runFor($shop->id, fn () => app(TenantRoleService::class)->ensureDefaultsForShop($shop->id));
        // Make the owner actually hold the owner role (factory may not).
        TenantContext::runFor($shop->id, fn () => $owner->forceFill(['role_id' => $roles['owner']->id])->save());

        return [$owner->fresh(), $shop, $roles];
    }

    private function userWithRole(Shop $shop, Role $role, string $mobile): User
    {
        return User::create([
            'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
            'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);
    }

    private function staffPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'New Cashier', 'mobile_number' => '9876600001',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ], $over);
    }

    // ── HTTP create provisioning ───────────────────────────────────────────

    public function test_owner_creates_staff_with_correct_shop_role_and_permissions(): void
    {
        [$owner, $shop, $roles] = $this->tenant();

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/staff', $this->staffPayload(['role_id' => $roles['staff']->id])));

        $res->assertRedirect();
        $created = User::where('mobile_number', '9876600001')->first();
        $this->assertNotNull($created);
        $this->assertSame($shop->id, $created->shop_id, 'staff scoped to owner shop');
        $this->assertSame($roles['staff']->id, $created->role_id);
        // Permissions flow through the role.
        TenantContext::runFor($shop->id, function () use ($created) {
            $this->assertFalse($created->fresh()->hasPermission('staff.manage'));
        });
    }

    public function test_staff_create_validates_required_fields(): void
    {
        [$owner, $shop, $roles] = $this->tenant();

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/staff', ['name' => '', 'mobile_number' => '123']));

        $res->assertSessionHasErrors(['name', 'mobile_number', 'password', 'role_id']);
        $this->assertSame(0, User::where('name', '')->count(), 'no partial user row on validation fail');
    }

    // ── Forge surface ──────────────────────────────────────────────────────

    public function test_forged_shop_id_in_create_is_ignored(): void
    {
        [$owner, $shop, $roles] = $this->tenant();
        [, $otherShop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/staff', $this->staffPayload([
                'role_id' => $roles['staff']->id,
                'shop_id' => $otherShop->id, // injected
            ])))->assertRedirect();

        $created = User::where('mobile_number', '9876600001')->first();
        $this->assertSame($shop->id, $created->shop_id, 'shop_id comes from auth, never the request body');
    }

    public function test_cannot_assign_a_role_from_another_shop(): void
    {
        [$owner, $shop] = $this->tenant();
        [, $otherShop] = $this->createRetailerTenant();
        $otherRoles = TenantContext::runFor($otherShop->id, fn () => app(TenantRoleService::class)->ensureDefaultsForShop($otherShop->id));

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/staff', $this->staffPayload(['role_id' => $otherRoles['staff']->id])));

        $res->assertSessionHasErrors('role_id'); // exists-where-shop_id rule blocks it
        $this->assertNull(User::where('mobile_number', '9876600001')->first());
    }

    public function test_cannot_assign_the_owner_role_to_new_staff(): void
    {
        [$owner, $shop, $roles] = $this->tenant();

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/staff', $this->staffPayload(['role_id' => $roles['owner']->id])));

        $res->assertSessionHasErrors('role_id'); // rule: name != owner
        $this->assertNull(User::where('mobile_number', '9876600001')->first());
    }

    public function test_cannot_promote_existing_staff_to_owner_via_update(): void
    {
        [$owner, $shop, $roles] = $this->tenant();
        $staff = $this->userWithRole($shop, $roles['staff'], '9876600002');

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->put(self::ERP . '/staff/' . $staff->id, [
                'name' => 'Staffer', 'mobile_number' => '9876600002',
                'role_id' => $roles['owner']->id, // attempt promotion to owner
            ]));

        $res->assertSessionHasErrors('role_id');
        $this->assertSame($roles['staff']->id, $staff->fresh()->role_id, 'role unchanged — no escalation to owner');
    }

    // ── Cross-shop isolation ───────────────────────────────────────────────

    public function test_owner_cannot_edit_another_shops_staff(): void
    {
        [$ownerA, $shopA] = $this->tenant();
        [, $shopB, $rolesB] = $this->tenant();
        $staffB = $this->userWithRole($shopB, $rolesB['staff'], '9876600050');

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/staff/' . $staffB->id . '/edit'));

        $res->assertForbidden(); // guardStaffTarget: shop_id mismatch
    }

    public function test_owner_cannot_terminate_another_shops_staff(): void
    {
        [$ownerA, $shopA] = $this->tenant();
        [, $shopB, $rolesB] = $this->tenant();
        $staffB = $this->userWithRole($shopB, $rolesB['staff'], '9876600051');

        TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->delete(self::ERP . '/staff/' . $staffB->id))->assertForbidden();

        $this->assertTrue((bool) $staffB->fresh()->is_active, 'cross-shop staff untouched');
    }

    // ── Role boundary: manager has no staff.manage ─────────────────────────

    public function test_manager_cannot_create_staff(): void
    {
        [, $shop, $roles] = $this->tenant();
        $manager = $this->userWithRole($shop, $roles['manager'], '9876600060');

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($manager)
            ->post(self::ERP . '/staff', $this->staffPayload(['role_id' => $roles['staff']->id])));

        $res->assertForbidden(); // can:staff.manage — manager lacks it
        $this->assertNull(User::where('mobile_number', '9876600001')->first());
    }

    public function test_staff_cannot_reach_staff_management(): void
    {
        [, $shop, $roles] = $this->tenant();
        $staff = $this->userWithRole($shop, $roles['staff'], '9876600061');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($staff)
            ->get(self::ERP . '/staff/create'))->assertForbidden();
    }

    // ── Lifecycle: deactivated staff blocked ───────────────────────────────

    public function test_deactivated_staff_cannot_log_in(): void
    {
        [, $shop, $roles] = $this->tenant();
        $staff = $this->userWithRole($shop, $roles['staff'], '9876600070');
        $staff->forceFill(['is_active' => false])->save();

        $res = $this->post(self::ERP . '/login', ['mobile_number' => '9876600070', 'password' => 'password']);
        $res->assertSessionHas('login_modal', 'account_inactive');
        $this->assertGuest();
    }

    // ── Device session scoping ─────────────────────────────────────────────

    public function test_owner_cannot_disconnect_another_shops_device(): void
    {
        [$ownerA, $shopA] = $this->tenant();
        [, $shopB, $rolesB] = $this->tenant();
        $staffB = $this->userWithRole($shopB, $rolesB['staff'], '9876600080');

        $deviceB = TenantContext::runFor($shopB->id, fn () => \App\Models\MobileDeviceSession::create([
            'shop_id' => $shopB->id, 'user_id' => $staffB->id,
            'device_name' => 'B-phone', 'platform' => 'android', 'logged_in_at' => now(),
        ]));

        TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->delete(self::ERP . '/settings/devices/' . $deviceB->id))
            ->assertNotFound(); // shop_id mismatch → 404

        $this->assertNull($deviceB->fresh()->logged_out_at, 'cross-shop device not revoked');
    }
}

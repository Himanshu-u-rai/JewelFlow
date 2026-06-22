<?php

namespace Tests\Unit\Staff;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff / Roles / Permissions — unit level (Module 5). The default role→permission
 * sets and shop-scoping of TenantRoleService, plus the permission helper. No HTTP.
 */
class StaffRolesUnitTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $phone = '9000000601'): Shop
    {
        return Shop::create([
            'name' => 'S', 'shop_type' => 'retailer', 'phone' => $phone,
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $phone,
            'is_active' => true, 'access_mode' => 'active',
        ]);
    }

    // ── Default role sets ──────────────────────────────────────────────────

    public function test_owner_role_gets_every_permission(): void
    {
        $shop = $this->shop();
        $roles = app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);

        $ownerPermCount = $roles['owner']->permissions()->count();
        $this->assertSame(Permission::count(), $ownerPermCount, 'owner holds all permissions');
    }

    public function test_manager_cannot_manage_staff_or_edit_settings(): void
    {
        $shop = $this->shop('9000000602');
        $roles = app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        $managerPerms = $roles['manager']->permissions()->pluck('name');

        // Manager = everything EXCEPT these two (the documented boundary).
        $this->assertFalse($managerPerms->contains('staff.manage'), 'manager must NOT manage staff');
        $this->assertFalse($managerPerms->contains('settings.edit'), 'manager must NOT edit settings');
        // But still holds a normal operational permission.
        $this->assertTrue($managerPerms->contains('sales.create'));
    }

    public function test_staff_role_is_a_restricted_whitelist(): void
    {
        $shop = $this->shop('9000000603');
        $roles = app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        $staffPerms = $roles['staff']->permissions()->pluck('name');

        // Staff must never hold management permissions.
        foreach (['staff.manage', 'staff.view', 'settings.edit', 'reports.export'] as $forbidden) {
            $this->assertFalse($staffPerms->contains($forbidden), "staff must NOT hold {$forbidden}");
        }
        $this->assertGreaterThan(0, $staffPerms->count(), 'staff still has its operational whitelist');
    }

    // ── Shop scoping ───────────────────────────────────────────────────────

    public function test_default_roles_are_scoped_to_their_shop(): void
    {
        $shopA = $this->shop('9000000604');
        $shopB = $this->shop('9000000605');
        $svc = app(TenantRoleService::class);
        $rolesA = $svc->ensureDefaultsForShop($shopA->id);
        $rolesB = $svc->ensureDefaultsForShop($shopB->id);

        $this->assertSame($shopA->id, $rolesA['owner']->shop_id);
        $this->assertSame($shopB->id, $rolesB['owner']->shop_id);
        $this->assertNotSame($rolesA['owner']->id, $rolesB['owner']->id, 'each shop has its own owner role');
    }

    public function test_ensure_defaults_is_idempotent(): void
    {
        $shop = $this->shop('9000000606');
        $svc = app(TenantRoleService::class);
        $svc->ensureDefaultsForShop($shop->id);
        $svc->ensureDefaultsForShop($shop->id); // re-run

        foreach (['owner', 'manager', 'staff'] as $name) {
            $this->assertSame(1, Role::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', $name)->count(),
                "{$name} role not duplicated on re-run");
        }
    }

    // ── Permission helper ──────────────────────────────────────────────────

    public function test_user_has_permission_reflects_its_role(): void
    {
        $shop = $this->shop('9000000607');
        $roles = app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);

        $staff = User::create([
            'mobile_number' => '9000000608', 'password' => bcrypt('x'), 'realm' => 'erp',
            'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $roles['staff']->id,
        ]);

        TenantContext::runFor($shop->id, function () use ($staff) {
            $fresh = $staff->fresh();
            $this->assertFalse($fresh->hasPermission('staff.manage'), 'staff lacks staff.manage');
            $this->assertFalse($fresh->hasPermission('settings.edit'));
        });
    }

    public function test_user_with_no_role_has_no_permissions(): void
    {
        $shop = $this->shop('9000000609');
        $u = User::create([
            'mobile_number' => '9000000610', 'password' => bcrypt('x'), 'realm' => 'erp',
            'is_active' => true, 'shop_id' => $shop->id, // no role_id
        ]);

        $this->assertFalse($u->fresh()->hasPermission('sales.create'));
        $this->assertFalse($u->fresh()->hasPermission('staff.manage'));
    }
}

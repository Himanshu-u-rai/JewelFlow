<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The load-bearing RBAC contract: a permission that is OFF for a role must deny
 * access through the SAME path every gate uses (Gate::before → User::hasPermission
 * → Role::hasPermission). These tests grant/revoke a real permission on a real
 * role and assert the gate flips, then prove the route middleware honours it.
 *
 * This closes the harness gap where createRetailerTenant() seeds an owner role
 * with NO permissions, so production gate enforcement was never exercised E2E.
 */
class RolePermissionEnforcementTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeUserWithRole(int $shopId, string $roleName): User
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $roleName) {
            $role = Role::firstOrCreate(
                ['name' => $roleName],
                ['display_name' => ucfirst($roleName), 'shop_id' => $shopId]
            );

            return User::factory()->create([
                'shop_id'   => $shopId,
                'role_id'   => $role->id,
                'is_active' => true,
            ]);
        });
    }

    public function test_gate_grants_only_when_the_permission_is_present_on_the_role(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $user = $this->makeUserWithRole($shop->id, 'staff');

        TenantContext::runFor($shop->id, function () use ($user) {
            $role = $user->role;

            // Baseline: no permission → gate denies.
            $role->revokePermission('inventory.view');
            $user->unsetRelation('role');
            $this->assertFalse($user->can('inventory.view'), 'gate must DENY when permission absent');
            $this->assertFalse($user->hasPermission('inventory.view'));

            // Grant → gate allows.
            $role->givePermission('inventory.view');
            $user->unsetRelation('role');
            $this->assertTrue($user->can('inventory.view'), 'gate must ALLOW when permission present');

            // Revoke again → gate denies again (no sticky cache).
            $role->revokePermission('inventory.view');
            $user->unsetRelation('role');
            $this->assertFalse($user->can('inventory.view'), 'gate must DENY again after revoke');
        });
    }

    public function test_holding_one_permission_never_leaks_into_another(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $user = $this->makeUserWithRole($shop->id, 'staff');

        TenantContext::runFor($shop->id, function () use ($user) {
            $role = $user->role;
            $role->syncPermissions(['inventory.view']); // ONLY this one
            $user->unsetRelation('role');

            $this->assertTrue($user->can('inventory.view'));
            // Every other permission must remain denied.
            foreach (['catalog.manage', 'reports.view', 'settings.edit', 'staff.manage', 'sales.create', 'pricing.update'] as $other) {
                $this->assertFalse($user->can($other), "holding inventory.view must NOT grant {$other}");
            }
        });
    }

    public function test_user_with_no_role_is_denied_everything(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $user = TenantContext::runFor($shop->id, fn () => User::factory()->create([
            'shop_id'   => $shop->id,
            'role_id'   => null,
            'is_active' => true,
        ]));

        TenantContext::runFor($shop->id, function () use ($user) {
            foreach (['inventory.view', 'reports.view', 'settings.edit', 'sales.create'] as $perm) {
                $this->assertFalse($user->hasPermission($perm), "roleless user must hold no permission ({$perm})");
                $this->assertFalse($user->can($perm), "roleless user must be denied {$perm}");
            }
        });
    }

    public function test_unknown_dotted_ability_is_denied_not_silently_allowed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $user = $this->makeUserWithRole($shop->id, 'staff');

        TenantContext::runFor($shop->id, function () use ($user) {
            $user->role->syncPermissions(['inventory.view']);
            $user->unsetRelation('role');

            // A made-up permission name must never resolve to allow.
            $this->assertFalse($user->can('totally.madeup.permission'));
            $this->assertFalse($user->can('inventory.delete.everything'));
        });
    }

    public function test_owner_cannot_inject_a_hidden_dhiran_permission_via_crafted_payload(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($owner, $shop) {
            // Owner with real (non-Dhiran) access, plus a staff role to edit.
            $owner->role->syncPermissions(
                Permission::where('group', '!=', 'dhiran')->orWhereNull('group')->pluck('name')->all()
            );
            Role::firstOrCreate(['name' => 'staff'], ['display_name' => 'Staff', 'shop_id' => $shop->id]);
        });
        $owner->unsetRelation('role');

        $staffRole = TenantContext::runFor($shop->id, fn () => Role::where('name', 'staff')->first());
        $dhiranIds = Permission::where('group', 'dhiran')->pluck('id')->all();
        $invView   = Permission::where('name', 'inventory.view')->value('id');

        // Drive the real controller action with a crafted payload that smuggles
        // every Dhiran id alongside one legitimate permission. Invoking the
        // controller (rather than the HTTP route) keeps the test off the tenant
        // route-binding harness while still exercising the exact sync guard.
        TenantContext::runFor($shop->id, function () use ($owner, $staffRole, $invView, $dhiranIds) {
            $this->actingAs($owner);
            $request = \Illuminate\Http\Request::create('/x', 'PATCH', [
                'permissions' => array_merge([(string) $invView], array_map('strval', $dhiranIds)),
            ]);
            app(\App\Http\Controllers\SettingsController::class)->updateRolePermissions($request, $staffRole);

            $applied = $staffRole->fresh()->permissions->pluck('name');
            $this->assertTrue($applied->contains('inventory.view'), 'legit permission should apply');
            foreach (['dhiran.view', 'dhiran.pay', 'dhiran.settings'] as $d) {
                $this->assertFalse($applied->contains($d), "crafted payload must NOT inject {$d}");
            }
        });
    }

    public function test_route_middleware_denies_when_permission_revoked(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Give the OWNER role a real, minimal permission set so the auth/tenant
        // stack passes but inventory.view can be toggled in isolation.
        TenantContext::runFor($shop->id, function () use ($owner) {
            $owner->role->syncPermissions(['inventory.view']);
        });

        // With inventory.view → categories index is reachable (not 403).
        $owner->unsetRelation('role');
        $resp = $this->actingAs($owner)->get(route('categories.index'));
        $this->assertNotSame(403, $resp->getStatusCode(), 'route must be reachable when permission present');

        // Revoke inventory.view → the SAME route must 403.
        TenantContext::runFor($shop->id, function () use ($owner) {
            $owner->role->revokePermission('inventory.view');
        });
        $owner->unsetRelation('role');

        $this->actingAs($owner)->get(route('categories.index'))->assertForbidden();
    }
}

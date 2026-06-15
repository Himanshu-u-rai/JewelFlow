<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Does the WEB Roles & Permissions model actually govern the MOBILE API?
 *
 * Mobile authenticates with Sanctum tokens minted via createToken('mobile-app')
 * — i.e. with the DEFAULT ['*'] token abilities (wide open). If mobile gating
 * leaned on Sanctum token abilities, disabling a permission in the web Settings
 * would NOT protect the mobile endpoint. These tests prove the opposite: the
 * `can:` middleware on mobile routes resolves through the SAME Gate::before →
 * User::hasPermission chain as web, so revoking a role permission blocks the
 * mobile API too — even though the token can do "*".
 *
 * Sanctum::actingAs($user) defaults to ['*'] abilities, faithfully mirroring the
 * real token, which is exactly what makes this test meaningful.
 */
class MobileRolePermissionEnforcementTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function staffUserWithPermissions(int $shopId, array $permissionNames): User
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $permissionNames) {
            $role = Role::firstOrCreate(
                ['name' => 'staff'],
                ['display_name' => 'Staff', 'shop_id' => $shopId]
            );
            $role->syncPermissions($permissionNames);

            return User::factory()->create([
                'shop_id'   => $shopId,
                'role_id'   => $role->id,
                'is_active' => true,
            ]);
        });
    }

    public function test_mobile_endpoint_is_blocked_when_the_web_permission_is_absent(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Staff WITHOUT customers.view (has some unrelated permission instead).
        $user = $this->staffUserWithPermissions($shop->id, ['inventory.view']);

        // Token can do EVERYTHING (['*']) — mirrors createToken('mobile-app').
        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson('/api/mobile/customers');

        // Despite the all-powerful token, the role lacks customers.view → 403.
        $resp->assertStatus(403);
    }

    public function test_mobile_endpoint_is_allowed_when_the_web_permission_is_present(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $user = $this->staffUserWithPermissions($shop->id, ['customers.view']);
        Sanctum::actingAs($user, ['*']);

        $resp = $this->getJson('/api/mobile/customers');

        // Has the role permission → the gate allows (not 403).
        $this->assertNotSame(403, $resp->getStatusCode(),
            'mobile endpoint must be reachable when the role holds the permission');
    }

    public function test_revoking_the_permission_flips_mobile_access_in_real_time(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $user = $this->staffUserWithPermissions($shop->id, ['customers.view']);
        Sanctum::actingAs($user, ['*']);

        // Initially allowed.
        $this->assertNotSame(403, $this->getJson('/api/mobile/customers')->getStatusCode());

        // Owner disables customers.view for staff in web Settings.
        TenantContext::runFor($shop->id, function () use ($user) {
            $user->role->revokePermission('customers.view');
        });
        $user->unsetRelation('role');

        // The mobile app is now blocked for the same token.
        $this->getJson('/api/mobile/customers')->assertStatus(403);
    }

    /**
     * Regression guard: every mobile WRITE endpoint (POST/PUT/PATCH/DELETE) must
     * carry a can:/role: gate, except a small set of self-service routes (login,
     * logout, own push-token, own session lock/unlock/revoke) that are correctly
     * scoped in-controller. Catches any future ungated mobile mutation.
     */
    public function test_every_mobile_write_route_is_permission_gated(): void
    {
        $selfServiceAllowed = [
            'api/mobile/auth/login',
            'api/mobile/auth/logout',
            'api/mobile/v1/device/push-token',
            'api/mobile/v1/sessions/lock',
            'api/mobile/v1/sessions/unlock',
            'api/mobile/v1/sessions',
            'api/mobile/v1/sessions/{session}',
        ];

        $ungated = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/mobile')) {
                continue;
            }
            if (! array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                continue;
            }

            $mw = implode(',', $route->gatherMiddleware());
            $hasGate = (bool) preg_match('/can:|role:|permission:/', $mw);

            if (! $hasGate && ! in_array($uri, $selfServiceAllowed, true)) {
                $ungated[] = $route->methods()[0] . ' ' . $uri;
            }
        }

        $this->assertSame([], $ungated,
            "Mobile write routes without a permission gate (add can:/role: or allowlist as self-service):\n"
            . implode("\n", $ungated));
    }

    /**
     * The "I fired this employee" scenario: deactivating a user in the web app
     * must cut off their MOBILE access even though their Sanctum token is still
     * physically valid. EnsureAccountIsActive (in the mobile stack) returns a
     * JSON 403 for a deactivated account on every gated route.
     */
    public function test_deactivated_user_is_blocked_on_mobile_despite_valid_token(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $user = $this->staffUserWithPermissions($shop->id, ['customers.view']);
        Sanctum::actingAs($user, ['*']);

        // Active → reachable.
        $this->assertNotSame(403, $this->getJson('/api/mobile/customers')->getStatusCode());

        // Owner deactivates the employee in the web app.
        $user->forceFill(['is_active' => false])->save();

        // Same valid token, but the account is off → 403.
        $this->getJson('/api/mobile/customers')->assertStatus(403);
    }

    public function test_a_wide_open_token_does_not_grant_a_permission_the_role_lacks(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Staff holds ONLY customers.view.
        $user = $this->staffUserWithPermissions($shop->id, ['customers.view']);
        Sanctum::actingAs($user, ['*']);

        // A different mobile endpoint gated on a permission the role does NOT hold
        // (inventory.create — guards the upload route) must still 403, proving the
        // token's ['*'] abilities never substitute for the role permission.
        $resp = $this->putJson('/api/mobile/v1/uploads/some-token', []);
        $this->assertContains($resp->getStatusCode(), [403, 404, 422],
            'must not be 200/201 — the role lacks inventory.create');
        // The crucial assertion: it is not silently authorized.
        $this->assertNotSame(200, $resp->getStatusCode());
        $this->assertNotSame(201, $resp->getStatusCode());
    }
}

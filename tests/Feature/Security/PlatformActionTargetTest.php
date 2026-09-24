<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformImpersonationSession;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * §7h — platform actions reach every shop by design; each still acts only on
 * what the admin selected. Where an action takes a shop from the route and
 * an id from the request, the id must belong to that shop; a bulk action
 * changes exactly the shops listed.
 */
class PlatformActionTargetTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private ?PlatformAdmin $admin = null;

    private function asSuperAdmin(): self
    {
        $admin = $this->admin ??= PlatformAdmin::create([
            'first_name' => 'T', 'last_name' => 'A', 'name' => 'TA', 'email' => 'target@example.com', 'mobile_number' => '9000000003',
            'password' => Hash::make('password'), 'role' => 'super_admin', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin, 'platform_admin')->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    private function role(int $shopId, string $name): Role
    {
        app(TenantRoleService::class)->ensureDefaultsForShop($shopId);

        return Role::withoutTenant()->where('shop_id', $shopId)->where('name', $name)->firstOrFail();
    }

    public function test_impersonating_through_one_shop_refuses_another_shops_user(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB] = $this->createRetailerTenant();

        $this->asSuperAdmin()->post(route('admin.shops.impersonate', $shopA), ['user_id' => $ownerB->id])->assertNotFound();
        $this->assertSame(0, PlatformImpersonationSession::count(), 'no impersonation of a user outside the route-bound shop');

        $this->asSuperAdmin()->post(route('admin.shops.impersonate', $shopA), ['user_id' => $ownerA->id])->assertRedirect(route('dashboard'));
        $this->assertSame(1, PlatformImpersonationSession::where('user_id', $ownerA->id)->count(), 'control: the shop\'s own user');
    }

    public function test_an_admin_role_change_refuses_another_shops_role(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $staffA = User::factory()->create(['shop_id' => $shopA->id, 'role_id' => $this->role((int) $shopA->id, 'staff')->id, 'is_active' => true]);
        $staffRoleA = $staffA->role_id;

        $this->asSuperAdmin()->patch(route('admin.users.role', $staffA), ['role_id' => $this->role((int) $shopB->id, 'manager')->id])
            ->assertSessionHasErrors('role_id');
        $this->assertSame($staffRoleA, $staffA->fresh()->role_id, "another shop's role is never assigned");

        $managerA = $this->role((int) $shopA->id, 'manager');
        $this->asSuperAdmin()->patch(route('admin.users.role', $staffA), ['role_id' => $managerA->id])->assertSessionHasNoErrors();
        $this->assertSame($managerA->id, $staffA->fresh()->role_id, 'control: the shop\'s own role');
    }

    public function test_a_bulk_action_changes_exactly_the_shops_listed(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        [, $shopC] = $this->createRetailerTenant();

        $this->asSuperAdmin()->post(route('admin.shops.bulk'), ['action' => 'suspend', 'shop_ids' => [$shopA->id, $shopB->id]])->assertRedirect();
        $this->assertSame(['suspended', 'suspended', 'active'], [$shopA->fresh()->access_mode, $shopB->fresh()->access_mode, $shopC->fresh()->access_mode]);

        $this->asSuperAdmin()->post(route('admin.shops.bulk'), ['action' => 'unsuspend', 'shop_ids' => [$shopA->id]])->assertRedirect();
        $this->assertSame(['active', 'suspended', 'active'], [$shopA->fresh()->access_mode, $shopB->fresh()->access_mode, $shopC->fresh()->access_mode]);
    }
}

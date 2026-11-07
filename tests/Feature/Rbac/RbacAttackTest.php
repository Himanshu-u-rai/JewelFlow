<?php

namespace Tests\Feature\Rbac;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Attacks on the role/permission system, kept as regression guards: data read through side doors, climbing to a
 * stronger role, reaching another shop's records, and access that should end when a permission or account does.
 */
class RbacAttackTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withoutVite();
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        ShopSubscription::query()->where('shop_id', $this->shop->id)->update(['status' => 'active']);
    }

    private function role(string $name, array $permissions, ?Shop $shop = null): Role
    {
        $role = new Role();
        $role->forceFill(['name' => $name, 'display_name' => ucfirst($name), 'shop_id' => ($shop ?? $this->shop)->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return $role;
    }

    private function member(Role $role, string $mobile): User
    {
        return User::factory()->create([
            'shop_id' => $role->shop_id, 'role_id' => $role->id, 'is_active' => true,
            'mobile_number' => $mobile, 'password' => Hash::make('Password123'),
        ]);
    }

    private function in(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, $fn);
    }

    // ── Side door: search ────────────────────────────────────────────────────────────────────────────────────────

    public function test_search_does_not_return_records_from_modules_the_user_cannot_open(): void
    {
        $this->in(fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Secret', 'last_name' => 'Buyer', 'mobile' => '9810099999']));
        $stockOnly = $this->member($this->role('stockist', ['inventory.view']), '9810000001');

        $this->actingAs($stockOnly);
        $response = $this->in(fn () => $this->getJson('/search/suggestions?type=customers&q=Secret'));
        $this->assertNotSame(200, $response->status() === 200 && $response->json() !== [] ? 200 : 0,
            'a stock-only user read customer names through search');
    }

    public function test_search_works_for_a_user_who_may_open_that_module(): void
    {
        $this->in(fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Asha', 'last_name' => 'Rao', 'mobile' => '9810099998']));
        $counter = $this->member($this->role('counter', ['customers.view', 'sales.pos']), '9810000002'); // no inventory.view

        $this->actingAs($counter);
        $this->in(fn () => $this->getJson('/search/suggestions?type=customers&q=Asha'))->assertOk()->assertJsonFragment(['label' => 'Asha Rao']);
    }

    // ── Climbing to a stronger role through staff management ─────────────────────────────────────────────────────

    public function test_a_non_owner_staff_manager_cannot_climb_to_a_stronger_role(): void
    {
        $manager = $this->role('manager', ['sales.view', 'sales.void', 'reports.view', 'settings.edit']);
        $supervisor = $this->role('supervisor', ['staff.view', 'staff.manage', 'sales.view']);
        $sup = $this->member($supervisor, '9810000003');
        $boss = $this->member($manager, '9810000004');
        $this->actingAs($sup);

        // 1. give themselves the Manager role
        $this->in(fn () => $this->put("/staff/{$sup->id}", ['name' => 'Sup', 'mobile_number' => '9810000003', 'role_id' => $manager->id]));
        $this->assertSame($supervisor->id, $sup->fresh()->role_id, 'supervisor promoted themselves to Manager');

        // 2. create a Manager account whose password they choose
        $this->in(fn () => $this->post('/staff', ['name' => 'Puppet', 'mobile_number' => '9810000005', 'role_id' => $manager->id,
            'password' => 'Password123', 'password_confirmation' => 'Password123']));
        $this->assertFalse(User::query()->where('mobile_number', '9810000005')->where('role_id', $manager->id)->exists(),
            'supervisor created a Manager account');

        // 3. take over the existing Manager by resetting their password
        $this->in(fn () => $this->put("/staff/{$boss->id}", ['name' => 'Boss', 'mobile_number' => '9810000004', 'role_id' => $manager->id,
            'password' => 'Taken0ver1', 'password_confirmation' => 'Taken0ver1']));
        $this->assertFalse(Hash::check('Taken0ver1', $boss->fresh()->password), 'supervisor reset the Manager\'s password');
    }

    public function test_the_owner_still_manages_any_staff_role(): void
    {
        $manager = $this->role('manager', ['sales.view', 'settings.edit']);
        $this->actingAs($this->owner);
        $this->in(fn () => $this->post('/staff', ['name' => 'New Manager', 'mobile_number' => '9810000006', 'role_id' => $manager->id,
            'password' => 'Password123', 'password_confirmation' => 'Password123']))->assertRedirect();
        $this->assertTrue(User::query()->where('mobile_number', '9810000006')->where('role_id', $manager->id)->exists());
    }

    public function test_nobody_is_given_the_owner_role_or_edits_the_owner(): void
    {
        $ownerRole = Role::withoutTenant()->where('shop_id', $this->shop->id)->where('name', 'owner')->firstOrFail();
        $cashier = $this->member($this->role('cashier', ['sales.pos']), '9810000007');
        $this->actingAs($this->owner);

        $this->in(fn () => $this->put("/staff/{$cashier->id}", ['name' => 'C', 'mobile_number' => '9810000007', 'role_id' => $ownerRole->id]));
        $this->assertNotSame($ownerRole->id, $cashier->fresh()->role_id);
        $this->in(fn () => $this->put("/staff/{$this->owner->id}", ['name' => 'X', 'mobile_number' => $this->owner->mobile_number, 'role_id' => $ownerRole->id]))->assertForbidden();
    }

    // ── Roles screen ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_role_permissions_cannot_be_edited_by_a_manager_or_across_shops_or_widened_to_hidden_ones(): void
    {
        $cashierRole = $this->role('cashier', ['sales.pos']);
        $mgr = $this->member($this->role('manager', ['settings.view', 'settings.edit', 'staff.manage']), '9810000008');
        $allIds = Permission::query()->pluck('id')->all();

        $this->actingAs($mgr);
        $this->in(fn () => $this->patch("/settings/roles/{$cashierRole->id}", ['permissions' => $allIds]))->assertForbidden();

        [, $otherShop] = $this->createRetailerTenant();
        $foreign = $this->role('cashier', ['sales.pos'], $otherShop);
        $this->actingAs($this->owner);
        $this->assertContains($this->in(fn () => $this->patch("/settings/roles/{$foreign->id}", ['permissions' => $allIds]))->status(), [403, 404]);
        $this->assertSame(1, $foreign->permissions()->count(), 'another shop\'s role was rewritten');

        $this->in(fn () => $this->patch("/settings/roles/{$cashierRole->id}", ['permissions' => $allIds]));
        $this->assertSame(0, $cashierRole->permissions()->where('group', 'dhiran')->count(), 'hidden Dhiran permissions were attached');
    }

    // ── Another shop's records ───────────────────────────────────────────────────────────────────────────────────

    public function test_another_shops_records_cannot_be_read_changed_or_referenced(): void
    {
        [, $otherShop] = $this->createRetailerTenant();
        $theirs = TenantContext::runFor($otherShop->id, fn () => Customer::create([
            'shop_id' => $otherShop->id, 'first_name' => 'Other', 'last_name' => 'Shop', 'mobile' => '9810077777',
        ]));
        $this->actingAs($this->owner);

        $this->assertContains($this->in(fn () => $this->get("/customers/{$theirs->id}"))->status(), [403, 404]);
        $this->assertContains($this->in(fn () => $this->patch("/customers/{$theirs->id}/archive"))->status(), [403, 404]);
        $this->assertNull($theirs->fresh()->archived_at ?? null);
        $this->in(fn () => $this->post('/repairs', ['customer_id' => $theirs->id, 'item_description' => 'Ring', 'metal_type' => 'gold']))
            ->assertSessionHasErrors('customer_id');
    }

    public function test_return_redispose_accepts_only_this_shops_gold_lot(): void
    {
        [, $otherShop] = $this->createRetailerTenant();
        $theirLot = $this->createMetalLot($otherShop->id);
        $item = $this->createItem($this->shop->id);
        $this->actingAs($this->owner);

        // Same rule as the melt-recovery step that really uses the lot: this shop's gold lots only.
        $this->in(fn () => $this->post("/returns/items/{$item->id}/redispose", ['disposition' => 'restocked', 'target_lot_id' => $theirLot->id]))
            ->assertSessionHasErrors('target_lot_id');
    }

    // ── Access that must end ─────────────────────────────────────────────────────────────────────────────────────

    public function test_access_ends_at_once_when_a_permission_or_the_account_is_taken_away(): void
    {
        $role = $this->role('counter', ['sales.pos', 'customers.view']);
        $staff = $this->member($role, '9810000009');
        $this->actingAs($staff);
        $this->in(fn () => $this->get('/customers'))->assertOk();

        $role->permissions()->detach(Permission::where('name', 'customers.view')->value('id'));
        $this->in(fn () => $this->get('/customers'))->assertForbidden();

        $token = $staff->createToken('phone')->plainTextToken;
        $this->actingAs($this->owner);
        $this->in(fn () => $this->delete("/staff/{$staff->id}"));
        $this->assertSame(0, $staff->tokens()->count(), 'a removed staff member kept phone access');
        $this->actingAs($staff->fresh());
        $this->assertNotSame(200, $this->in(fn () => $this->get('/pos'))->status(), 'a removed staff member kept web access');
    }

    public function test_a_shop_user_never_reaches_the_platform_admin_console(): void
    {
        $this->actingAs($this->owner);
        $response = $this->get('/admin');
        $this->assertNotSame(200, $response->status());
        $this->assertStringNotContainsString('Control Tower', $response->getContent());
    }

    public function test_profile_update_cannot_change_role_shop_or_status(): void
    {
        $mgr = $this->member($this->role('manager', ['sales.view']), '9810000010');
        [, $otherShop] = $this->createRetailerTenant();
        $this->actingAs($mgr);
        $this->in(fn () => $this->patch('/profile', ['name' => 'M', 'role_id' => 1, 'shop_id' => $otherShop->id, 'is_active' => false, 'realm' => 'dhiran']));

        $fresh = $mgr->fresh();
        $this->assertSame($this->shop->id, $fresh->shop_id);
        $this->assertSame($mgr->role_id, $fresh->role_id);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertSame('erp', $fresh->realm);
    }
}

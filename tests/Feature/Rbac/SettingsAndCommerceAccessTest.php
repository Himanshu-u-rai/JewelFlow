<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Owner decisions, 2026-11-07:
 *  - Cashier/staff get only the read-only General Settings page by default; every other settings tab needs a
 *    permission the owner grants.
 *  - Cashier/staff never edit their own details (profile, mobile, password); the owner changes them.
 *  - Owner AND manager may buy/renew the plan, read the shop's bills and add/remove business editions; cashier/staff
 *    never, whatever permissions they are given.
 */
class SettingsAndCommerceAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withoutVite();

        [, $this->shop] = $this->createRetailerTenant();
        ShopSubscription::query()->where('shop_id', $this->shop->id)->update(['status' => 'active']);
    }

    private function member(string $roleName, array $permissions = []): User
    {
        $role = new Role();
        $role->forceFill(['name' => $roleName, 'display_name' => ucfirst($roleName), 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create([
            'shop_id' => $this->shop->id,
            'role_id' => $role->id,
            'is_active' => true,
            'password' => Hash::make('password'),
        ]);
    }

    public function test_cashier_without_settings_permissions_sees_only_general_settings(): void
    {
        $cashier = $this->member('cashier', ['sales.view', 'sales.pos']);

        $this->actingAs($cashier)->get('/settings')->assertRedirect(route('settings.edit', ['tab' => 'general']));
        $this->actingAs($cashier)->get('/settings?tab=general')
            ->assertOk()
            ->assertSee('General Settings')
            ->assertDontSee('Plan &amp; Billing', false)
            ->assertDontSee('Business Editions');
        $this->actingAs($cashier)->get('/settings?tab=shop')->assertRedirect(route('settings.edit', ['tab' => 'general']));
    }

    public function test_cashier_given_settings_rights_still_cannot_edit_own_details_or_buy(): void
    {
        $cashier = $this->member('cashier', ['settings.view', 'settings.edit']);

        // The owner turned shop settings on for this role: that part works.
        $this->actingAs($cashier)->get('/settings?tab=shop')->assertOk();

        // Own details: never.
        $this->actingAs($cashier)->get('/profile')->assertForbidden();
        $this->actingAs($cashier)->get('/settings?tab=profile')->assertRedirect(route('settings.edit', ['tab' => 'general']));
        $this->actingAs($cashier)->put('/password', [
            'current_password' => 'password', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertForbidden();
        $this->assertTrue(Hash::check('password', $cashier->fresh()->password));

        // Plan, bills, editions: never.
        $this->actingAs($cashier)->get('/subscription/plans')->assertForbidden();
        $this->actingAs($cashier)->get('/subscription')->assertForbidden();
        $this->actingAs($cashier)->get('/billing')->assertForbidden();
        $this->actingAs($cashier)->post('/settings/services/request-add', ['edition' => 'manufacturer', 'reason' => 'We started making jewellery'])->assertForbidden();
        $this->actingAs($cashier)->get('/settings?tab=services')->assertRedirect();
        $this->actingAs($cashier)->get('/settings?tab=subscription')->assertRedirect();
    }

    public function test_manager_can_buy_and_manage_editions_and_edit_own_details_without_settings_rights(): void
    {
        $manager = $this->member('manager', ['sales.view']);

        // Plans/status may forward to each other depending on the shop's plan rows, exactly as for the owner: what
        // matters is that the manager is not refused.
        $this->assertNotSame(403, $this->actingAs($manager)->get('/subscription/plans')->status());
        $this->assertNotSame(403, $this->actingAs($manager)->get('/subscription')->status());
        $this->actingAs($manager)->get('/billing')->assertRedirect(route('subscription.status')); // the bills list lives on the status page
        $this->actingAs($manager)->get('/settings?tab=subscription')->assertOk()->assertSee('Change Plan');
        $this->actingAs($manager)->get('/settings?tab=services')->assertOk();
        $this->assertNotSame(403, $this->actingAs($manager)
            ->post('/settings/services/request-add', ['edition' => 'manufacturer', 'reason' => 'We started making jewellery'])
            ->status());

        $this->actingAs($manager)->get('/profile')->assertOk();
        $this->actingAs($manager)->put('/password', [
            'current_password' => 'password', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewPassword123!', $manager->fresh()->password));
    }
}

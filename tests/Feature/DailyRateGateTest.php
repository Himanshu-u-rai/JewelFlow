<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The daily metal-rate prompt is a server-side gate, not only a modal: while an
 * active retail shop's owner has not saved today's rates, the server refuses every
 * page and action except saving the rates and logging out. Removing the modal in
 * the browser therefore unlocks nothing. Staff keep their existing behaviour.
 */
class DailyRateGateTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    protected bool $dailyRateGate = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_owner_without_todays_rates_is_sent_to_the_rate_gate_from_any_page(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id, ['first_name' => 'ZZSecret', 'last_name' => 'Buyer']);
        foreach ([route('dashboard'), route('customers.index'), route('customers.show', $customer), route('invoices.index'), route('settings.edit')] as $url) {
            TenantContext::set($shop->id); // record routes bind under the shop; each request clears it
            $response = $this->actingAs($owner)->get($url);
            $this->assertSame([302, route('pricing.daily-rates.gate')], [$response->status(), $response->headers->get('Location')], $url);
        }
    }

    public function test_the_gate_page_shows_the_rate_form_and_no_shop_data(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createCustomer($shop->id, ['first_name' => 'ZZSecret', 'last_name' => 'Buyer']);

        $this->actingAs($owner)->get(route('pricing.daily-rates.gate'))
            ->assertOk()
            ->assertSee('action="'.route('settings.pricing.save-rates').'"', false)
            ->assertDontSee('ZZSecret');
    }

    public function test_owner_without_todays_rates_cannot_write_anything(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('customers.store'), ['first_name' => 'Should', 'last_name' => 'NotExist', 'mobile' => '9300000123'])
            ->assertRedirect(route('pricing.daily-rates.gate'));

        $this->assertFalse(Customer::withoutTenant()->where('shop_id', $shop->id)->where('first_name', 'Should')->exists());
    }

    public function test_json_requests_are_refused_with_423(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)->getJson(route('customers.index'))
            ->assertStatus(423)
            ->assertJson(['reason' => 'daily_rates_required']);
    }

    public function test_saving_todays_rates_opens_the_shop(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('settings.pricing.save-rates'), [
            'context' => 'modal', 'gold_24k_rate_per_gram' => '7200', 'silver_999_rate_per_kg' => '92000',
        ])->assertRedirect(route('dashboard'));

        $this->actingAs($owner)->get(route('customers.index'))->assertOk();
        $this->actingAs($owner)->get(route('pricing.daily-rates.gate'))->assertRedirect(route('dashboard'));
    }

    public function test_owner_with_todays_rates_is_not_gated(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $owner);

        $this->actingAs($owner)->get(route('customers.index'))->assertOk();
    }

    public function test_owner_can_still_log_out(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('logout'));
        $this->assertGuest();
    }

    public function test_staff_keep_their_existing_behaviour(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $staff = $this->staffWith($shop, ['customers.view']);

        $this->actingAs($staff)->get(route('customers.index'))->assertOk();
    }

    public function test_manufacturer_owner_is_not_gated(): void
    {
        [$owner] = $this->createManufacturerTenant();

        $this->actingAs($owner)->get(route('customers.index'))->assertOk();
    }

    public function test_gate_waits_for_opening_setup_and_skips_shops_that_cannot_act(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::set($shop->id);
        $pricing = app(ShopPricingService::class);
        $this->assertTrue($pricing->ownerMustEnterDailyRates($owner->fresh()));

        Shop::withoutGlobalScopes()->whereKey($shop->id)->update(['access_mode' => 'suspended']);
        $this->assertFalse($pricing->ownerMustEnterDailyRates($owner->fresh()), 'A shop that cannot act gets the notice, not the gate.');

        Shop::withoutGlobalScopes()->whereKey($shop->id)->update(['access_mode' => 'active']);
        \App\Models\ShopPreferences::withoutTenant()->where('shop_id', $shop->id)->update(['opening_setup_skipped_at' => null]);
        $this->assertFalse($pricing->ownerMustEnterDailyRates($owner->fresh()), 'Opening setup comes first.');
    }

    private function staffWith(Shop $shop, array $permissions): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'is_active' => true]);
    }
}

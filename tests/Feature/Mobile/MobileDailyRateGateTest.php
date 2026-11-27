<?php

namespace Tests\Feature\Mobile;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The app's API enforces the same daily metal-rate gate as the web: until the
 * owner of an active retail shop saves today's rates, every business endpoint
 * answers 423 `daily_rates_required`, whatever the app shows. Only signing
 * in/out, the caller's own session, the boot payload and today's rates pass.
 */
class MobileDailyRateGateTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    protected bool $dailyRateGate = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();
    }

    public function test_owner_without_todays_rates_gets_423_from_business_endpoints(): void
    {
        [$owner] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);

        foreach (['/api/mobile/dashboard', '/api/mobile/customers', '/api/mobile/invoices', '/api/mobile/v1/cashbook'] as $url) {
            $this->getJson($url)->assertStatus(423)->assertJson(['error' => 'daily_rates_required']);
        }
    }

    public function test_owner_without_todays_rates_cannot_write_through_the_api(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);

        $this->postJson('/api/mobile/customers', ['first_name' => 'Should', 'last_name' => 'NotExist', 'mobile' => '9300000124'])
            ->assertStatus(423);

        $this->assertFalse(Customer::withoutTenant()->where('shop_id', $shop->id)->where('first_name', 'Should')->exists());
    }

    public function test_a_client_without_a_json_accept_header_still_gets_423(): void
    {
        [$owner] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);

        $this->get('/api/mobile/customers')->assertStatus(423);
    }

    public function test_sign_in_state_boot_and_todays_rates_stay_reachable(): void
    {
        [$owner] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);

        $this->getJson('/api/mobile/auth/me')->assertOk();
        $this->getJson('/api/mobile/bootstrap')->assertOk();
        $this->getJson('/api/mobile/pricing/today')->assertOk();
        $this->getJson('/api/mobile/v1/sessions/me')->assertOk();
        $this->postJson('/api/mobile/auth/logout')->assertOk();
    }

    public function test_saving_todays_rates_in_the_app_opens_the_api(): void
    {
        [$owner] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);

        $this->postJson('/api/mobile/pricing/today', ['gold_24k_rate_per_gram' => 7200, 'silver_999_rate_per_kg' => 92000])
            ->assertOk();

        $this->getJson('/api/mobile/customers')->assertOk();
        $this->getJson('/api/mobile/v1/cashbook')->assertOk();
    }

    public function test_owner_with_todays_rates_is_not_gated(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $owner);
        Sanctum::actingAs($owner);

        $this->getJson('/api/mobile/customers')->assertOk();
    }

    public function test_staff_keep_their_existing_behaviour(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $role = new Role();
        $role->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', ['customers.view'])->pluck('id'));
        $staff = User::factory()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/mobile/customers')->assertOk();
    }

    public function test_manufacturer_owner_is_not_gated(): void
    {
        [$owner] = $this->createManufacturerTenant();
        Sanctum::actingAs($owner);

        $this->getJson('/api/mobile/auth/me')->assertOk();
        $this->getJson('/api/mobile/dashboard')->assertOk();
    }
}

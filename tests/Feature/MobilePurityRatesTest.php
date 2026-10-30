<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The app's "Rates by purity" page: the web's Today's Resolved Rates table
 * (every purity, its rate per gram, Active Override / Base Derived) and its
 * same-day override, through the same ShopPricingService. Owner only (owner's call).
 */
class MobilePurityRatesTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{0: User, 1: \App\Models\Shop} */
    private function ownerWithRates(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $owner); // 24K gold ₹7,200/g, pure silver ₹92/g
        Sanctum::actingAs($owner);

        return [$owner, $shop];
    }

    private function row(array $rows, string $metal, string $label): array
    {
        return collect($rows)->first(fn ($r) => $r['metal_type'] === $metal && $r['label'] === $label);
    }

    public function test_every_purity_with_todays_rate_worked_out_from_the_base_rates(): void
    {
        $this->ownerWithRates();

        $rows = $this->getJson('/api/mobile/pricing/purity-rates')->assertOk()->json('purity_rates');

        $this->assertEqualsWithDelta(6600.0, $this->row($rows, 'gold', '22K')['rate_per_gram'], 0.0001);
        $this->assertEqualsWithDelta(5400.0, $this->row($rows, 'gold', '18K')['rate_per_gram'], 0.0001);
        $this->assertEqualsWithDelta(85.1, $this->row($rows, 'silver', '925')['rate_per_gram'], 0.0001);
        $this->assertFalse($this->row($rows, 'gold', '22K')['is_override']);
    }

    public function test_the_owner_sets_their_own_rate_for_a_purity_and_it_survives_a_base_rate_resave(): void
    {
        [$owner, $shop] = $this->ownerWithRates();
        $id = $this->row($this->getJson('/api/mobile/pricing/purity-rates')->json('purity_rates'), 'gold', '22K')['profile_id'];

        $rows = $this->postJson("/api/mobile/pricing/purity-rates/{$id}/override", ['rate_per_gram' => 6650])
            ->assertOk()->json('purity_rates');
        $this->assertEqualsWithDelta(6650.0, $this->row($rows, 'gold', '22K')['rate_per_gram'], 0.0001);
        $this->assertTrue($this->row($rows, 'gold', '22K')['is_override']);

        // As on the web: saving today's base rates again keeps the same-day override.
        $this->postJson('/api/mobile/pricing/today', ['gold_24k_rate_per_gram' => 7300, 'silver_999_rate_per_kg' => 92000])->assertOk();
        $rows = $this->getJson('/api/mobile/pricing/purity-rates')->json('purity_rates');
        $this->assertEqualsWithDelta(6650.0, $this->row($rows, 'gold', '22K')['rate_per_gram'], 0.0001);
        $this->assertEqualsWithDelta(5475.0, $this->row($rows, 'gold', '18K')['rate_per_gram'], 0.0001);
    }

    public function test_no_override_before_todays_base_rates_are_saved(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        $rows = $this->getJson('/api/mobile/pricing/purity-rates')->assertOk()->json('purity_rates');
        $this->assertNull($this->row($rows, 'gold', '22K')['rate_per_gram']);

        $this->postJson('/api/mobile/pricing/purity-rates/' . $this->row($rows, 'gold', '22K')['profile_id'] . '/override', ['rate_per_gram' => 6650])
            ->assertStatus(422);
    }

    public function test_only_the_owner_can_open_or_change_it(): void
    {
        [$owner, $shop] = $this->ownerWithRates();
        $id = $this->row($this->getJson('/api/mobile/pricing/purity-rates')->json('purity_rates'), 'gold', '22K')['profile_id'];

        // A manager who CAN set today's base rates (pricing.update) — still not the owner.
        $role = new \App\Models\Role();
        $role->forceFill(['name' => 'manager', 'display_name' => 'Manager', 'shop_id' => $shop->id])->save();
        $manager = $this->createOwnerUser($shop, $role);
        $this->grantOnlyPermissions($manager, ['pricing.update']);
        Sanctum::actingAs($manager->fresh());

        $this->getJson('/api/mobile/pricing/purity-rates')->assertForbidden();
        $this->postJson("/api/mobile/pricing/purity-rates/{$id}/override", ['rate_per_gram' => 1])->assertForbidden();
    }

    public function test_another_shops_purity_cannot_be_overridden(): void
    {
        [, $otherShop] = $this->createRetailerTenant();
        $this->ownerWithRates();
        $foreign = \App\Models\ShopMetalPurityProfile::withoutTenant()->where('shop_id', $otherShop->id)->value('id')
            ?? \App\Models\ShopMetalPurityProfile::withoutTenant()->create([
                'shop_id' => $otherShop->id, 'metal_type' => 'gold', 'purity_value' => 22, 'basis' => 'karat_24',
                'code' => '22', 'label' => '22K', 'sort_order' => 1, 'is_active' => true,
            ])->id;

        $this->postJson("/api/mobile/pricing/purity-rates/{$foreign}/override", ['rate_per_gram' => 1])->assertNotFound();
    }
}

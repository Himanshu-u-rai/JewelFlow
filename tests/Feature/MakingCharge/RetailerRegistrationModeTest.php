<?php

namespace Tests\Feature\MakingCharge;

use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MC-5: retailer making resolves at REGISTRATION (rate known) and bakes into
 * selling_price. The sale path stays rate-free. Flag-gated. Tested directly on
 * ShopPricingService::computeRetailerCostPayload (no HTTP/permission layer).
 *
 * Fixture: 22k gold, gold_24k ₹7200/g ⇒ 22k ≈ ₹6600/g. Net 10g ⇒ metal cost
 * ₹66,000 (the service's resolvedRate path).
 */
class RetailerRegistrationModeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function payload(int $shopId, array $attrs): array
    {
        [$user, $shop] = [null, \App\Models\Shop::withoutGlobalScopes()->findOrFail($shopId)];
        return TenantContext::runFor($shopId, fn () =>
            app(ShopPricingService::class)->computeRetailerCostPayload($shop, array_merge([
                'metal_type' => 'gold', 'purity' => 22,
                'gross_weight' => 10, 'stone_weight' => 0,
            ], $attrs))
        );
    }

    public function test_flag_off_uses_flat_making_byte_identical(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        config(['features.making_charge_modes' => false]);

        $p = $this->payload($shop->id, [
            'making_charges' => 500,
            'making_charge_type' => 'percentage', // must be ignored when flag off
            'making_charge_value' => 12,
        ]);

        $this->assertEqualsWithDelta(500.0, (float) $p['making_charges'], 0.001);
        $this->assertNull($p['making_charge_type']);
        // selling_price = metal cost + overhead(500) — flat making preserved.
        $this->assertEqualsWithDelta($p['cost_price'] + 500.0, (float) $p['selling_price'], 0.01);
    }

    public function test_flag_on_percentage_resolves_at_registration_into_selling_price(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        config(['features.making_charge_modes' => true]);

        $p = $this->payload($shop->id, [
            'making_charge_type' => 'percentage',
            'making_charge_value' => 12,
        ]);

        // making = 12% of metal cost; baked into selling_price.
        $expectedMaking = round(((float) $p['cost_price']) * 0.12, 2);
        $this->assertEqualsWithDelta($expectedMaking, (float) $p['making_charges'], 0.01);
        $this->assertSame('percentage', $p['making_charge_type']);
        $this->assertEqualsWithDelta(12.00, (float) $p['making_charge_value'], 0.001);
        $this->assertEqualsWithDelta($p['cost_price'] + $expectedMaking, (float) $p['selling_price'], 0.01);
    }

    public function test_flag_on_per_gram_resolves_on_net_weight(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        config(['features.making_charge_modes' => true]);

        $p = $this->payload($shop->id, [
            'making_charge_type' => 'per_gram',
            'making_charge_value' => 300,
        ]);

        // ₹300/g × 10g net = ₹3000.
        $this->assertEqualsWithDelta(3000.00, (float) $p['making_charges'], 0.01);
        $this->assertSame('per_gram', $p['making_charge_type']);
    }
}

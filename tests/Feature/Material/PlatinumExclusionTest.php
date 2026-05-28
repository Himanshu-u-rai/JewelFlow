<?php

namespace Tests\Feature\Material;

use App\Services\MetalRegistry;
use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P4 — Platinum specification hardening.
 *
 * Proves platinum is luxury-piece-priced, NOT gold-lite: its purity never
 * becomes a fine weight, its price is never rate-derived, and it cannot enter
 * the vault as a purity-reconciled gram position.
 */
class PlatinumExclusionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_platinum_pricing_is_not_rate_derived(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);

        $payload = TenantContext::runFor($shop->id, fn () => app(ShopPricingService::class)->computeRetailerCostPayload($shop, [
            'metal_type'   => 'platinum',
            'purity'       => 95,
            'gross_weight' => 6,
            'stone_weight' => 0,
            'selling_price' => 85000,
        ]));

        // No daily-rate derivation for platinum.
        $this->assertNull($payload['resolved_rate_per_gram']);
        // Price is the operator's entered figure, not rate × fine weight.
        $this->assertSame(85000.0, round((float) $payload['selling_price'], 2));
    }

    public function test_platinum_net_weight_cannot_become_fine_weight(): void
    {
        // Even though a platinum item may store a purity (95) and a net weight,
        // the fine-weight authority refuses to turn it into fine weight.
        $this->assertNull(MetalRegistry::fineWeight('platinum', 6.0, 95));
        $this->assertNull(MetalRegistry::fineWeightMultiplier('platinum', 95));
    }

    public function test_platinum_is_not_an_accounting_or_reconciliation_metal(): void
    {
        $this->assertFalse(MetalRegistry::purityIsAccountingTruth('platinum'));
        $this->assertTrue(MetalRegistry::purityIsSpecification('platinum'));
        $this->assertNotContains('platinum', MetalRegistry::accountingTruthMetals());
    }
}

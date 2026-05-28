<?php

namespace Tests\Feature\Mobile\V1;

use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M1 — GET /api/mobile/v1/registry/materials contract.
 *
 * Locks the registry payload shape:
 *   - Pilot shops (gold/silver only) see exactly those two metals as Class A
 *     accounting-truth materials with fine-weight support.
 *   - Shops with platinum opted-in see it surface as Class B with the
 *     "lightweight" purity selector and reference-price support.
 *   - Every snapshot carries a Class C stone descriptor.
 *   - The registry_version field tracks MetalRegistry::REGISTRY_VERSION.
 *   - Drift-prone field names (rate_per_gram, business_date,
 *     resolved_rate_per_gram) MUST NOT leak into this read-only metadata
 *     payload — those belong to the rate engine, not the registry.
 */
class RegistryMaterialsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ENDPOINT = '/api/mobile/v1/registry/materials';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        MetalRegistry::clearShopCache();
        parent::tearDown();
    }

    public function test_pilot_shop_returns_gold_silver_only(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->enableMetalsForShop($shop->id, ['gold', 'silver']);
        MetalRegistry::clearShopCache($shop->id);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('data.shop_id', $shop->id);
        $response->assertJsonPath('data.enabled_metals', ['gold', 'silver']);
        $response->assertJsonPath('data.metals.gold.pricing_class', 'A');
        $response->assertJsonPath('data.metals.gold.purity_is_accounting_truth', true);
        $response->assertJsonPath('data.metals.gold.fine_weight_supported', true);
        $response->assertJsonPath('data.metals.gold.reference_price_supported', false);
        $response->assertJsonPath('data.metals.silver.pricing_class', 'A');
        $response->assertJsonPath('data.metals.silver.purity_is_accounting_truth', true);
        $response->assertJsonPath('data.metals.silver.fine_weight_supported', true);
        $response->assertJsonPath('data.metals.silver.reference_price_supported', false);

        // No Tier-2 metals leak in.
        $this->assertArrayNotHasKey('platinum', $response->json('data.metals'));
        $this->assertArrayNotHasKey('copper', $response->json('data.metals'));
    }

    public function test_platinum_enabled_shop_includes_pricing_class_b(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->enableMetalsForShop($shop->id, ['gold', 'silver', 'platinum']);
        MetalRegistry::clearShopCache($shop->id);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $this->assertContains('platinum', $response->json('data.enabled_metals'));

        $response->assertJsonPath('data.metals.platinum.pricing_class', 'B');
        $response->assertJsonPath('data.metals.platinum.purity_is_accounting_truth', false);
        $response->assertJsonPath('data.metals.platinum.fine_weight_supported', false);
        $response->assertJsonPath('data.metals.platinum.reference_price_supported', true);
        $response->assertJsonPath('data.metals.platinum.purity_selector_mode', 'lightweight');
        $response->assertJsonPath('data.metals.platinum.purity_label', 'Hallmark grade');
    }

    public function test_stones_section_always_present_with_class_c(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->enableMetalsForShop($shop->id, ['gold', 'silver']);
        MetalRegistry::clearShopCache($shop->id);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('data.stones.pricing_class', 'C');
        $response->assertJsonPath('data.stones.value_field', 'stone_amount');
        $response->assertJsonPath('data.stones.purity_selector_mode', 'hidden');
    }

    public function test_registry_version_is_stable_string(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->enableMetalsForShop($shop->id, ['gold', 'silver']);
        MetalRegistry::clearShopCache($shop->id);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('data.registry_version', MetalRegistry::REGISTRY_VERSION);
        $response->assertJsonPath('meta.registry_version', MetalRegistry::REGISTRY_VERSION);
        $this->assertSame(MetalRegistry::REGISTRY_VERSION, MetalRegistry::registryVersion());
    }

    public function test_response_contains_no_hardcoded_literal_drift_field_names(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->enableMetalsForShop($shop->id, ['gold', 'silver', 'platinum']);
        MetalRegistry::clearShopCache($shop->id);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $body = $response->getContent();

        // These field names belong to the rate-engine path, NOT the
        // capability registry. If any of them appears in the payload the
        // class-leak protection has failed.
        foreach (['rate_per_gram', 'business_date', 'resolved_rate_per_gram'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                "Registry payload must not contain rate-engine field '{$forbidden}'."
            );
        }
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson(self::ENDPOINT);

        $response->assertStatus(401);
    }

    /**
     * Insert/upsert rows into shop_enabled_metals for the given metals. Uses
     * the project-wide PostgreSQL boolean pattern (DB::raw('true')) per
     * CONSTITUTION §2 Pattern F4.
     */
    private function enableMetalsForShop(int $shopId, array $metals): void
    {
        $now = now();

        foreach ($metals as $metal) {
            DB::table('shop_enabled_metals')->updateOrInsert(
                ['shop_id' => $shopId, 'metal_type' => $metal],
                [
                    'enabled' => DB::raw('true'),
                    'enabled_at' => $now,
                    'enabled_by_user_id' => null,
                    'notes' => 'test fixture',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}

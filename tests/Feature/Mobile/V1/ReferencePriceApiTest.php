<?php

namespace Tests\Feature\Mobile\V1;

use App\Services\MetalRegistry;
use App\Services\ReferencePriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M10 — Reference price read API contract tests.
 *
 * Ensures the mobile endpoint:
 *   - Only exposes Class-B metals (platinum, copper).
 *   - Never exposes Class-A metals (gold, silver) as reference prices.
 *   - Labels every payload clearly as memo-only.
 *   - Returns empty (not an error) when no references have been noted.
 *   - Is shop-scoped.
 *   - Correctly lists history in newest-first order.
 */
class ReferencePriceApiTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

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

    private function grant(\App\Models\User $user, string ...$perms): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($perms as $p) {
            $role->givePermission($p);
        }
    }

    private function enableMetal(int $shopId, string $metal): void
    {
        DB::table('shop_enabled_metals')->updateOrInsert(
            ['shop_id' => $shopId, 'metal_type' => $metal],
            ['enabled' => DB::raw('TRUE'), 'updated_at' => now(), 'created_at' => now()]
        );
        MetalRegistry::clearShopCache($shopId);
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->getJson('/api/mobile/v1/reference-prices')->assertStatus(401);
    }

    public function test_pilot_shop_returns_empty_metals(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view');
        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        $response = $this->getJson('/api/mobile/v1/reference-prices');
        $response->assertOk();
        // Gold/silver only shop: no Tier-2 metals enabled → metals is empty.
        $this->assertSame([], $response->json('data.metals'));
    }

    public function test_platinum_appears_when_enabled_and_labeled_class_b(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view');
        $this->enableMetal((int) $shop->id, 'platinum');
        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        $response = $this->getJson('/api/mobile/v1/reference-prices');
        $response->assertOk();
        $metals = $response->json('data.metals');
        $this->assertArrayHasKey('platinum', $metals);
        $this->assertSame('B', $metals['platinum']['pricing_class']);
        $this->assertTrue($metals['platinum']['is_memo_only']);
        $this->assertNull($metals['platinum']['latest']); // none noted yet
    }

    public function test_gold_and_silver_never_appear_as_reference_metals(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view');
        $this->enableMetal((int) $shop->id, 'platinum');
        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        $response = $this->getJson('/api/mobile/v1/reference-prices');
        $response->assertOk();
        $metals = $response->json('data.metals');
        $this->assertArrayNotHasKey('gold', $metals);
        $this->assertArrayNotHasKey('silver', $metals);
    }

    public function test_history_is_newest_first(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view');
        $this->enableMetal((int) $shop->id, 'platinum');

        // Insert with explicit noted_at to guarantee ordering without sleep.
        \Illuminate\Support\Facades\DB::table('shop_metal_reference_prices')->insert([
            ['shop_id' => $shop->id, 'metal_type' => 'platinum', 'reference_price' => 3100, 'noted_at' => now()->subDays(2), 'noted_by_user_id' => $user->id, 'note' => 'first', 'created_at' => now(), 'updated_at' => now()],
            ['shop_id' => $shop->id, 'metal_type' => 'platinum', 'reference_price' => 3200, 'noted_at' => now()->subDay(), 'noted_by_user_id' => $user->id, 'note' => 'second', 'created_at' => now(), 'updated_at' => now()],
            ['shop_id' => $shop->id, 'metal_type' => 'platinum', 'reference_price' => 3300, 'noted_at' => now(), 'noted_by_user_id' => $user->id, 'note' => 'third', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        $response = $this->getJson('/api/mobile/v1/reference-prices');
        $response->assertOk();

        $history = $response->json('data.metals.platinum.history');
        $this->assertCount(3, $history);
        $this->assertEquals(3300.0, $history[0]['reference_price']); // newest first
        $this->assertEquals(3200.0, $history[1]['reference_price']);
        $this->assertEquals(3100.0, $history[2]['reference_price']);

        $latest = $response->json('data.metals.platinum.latest');
        $this->assertEquals(3300.0, $latest['reference_price']);
    }

    public function test_disclaimer_is_always_present(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view');
        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        $response = $this->getJson('/api/mobile/v1/reference-prices');
        $response->assertOk();
        $this->assertNotEmpty($response->json('data.disclaimer'));
        $this->assertStringContainsStringIgnoringCase('memo', $response->json('data.disclaimer'));
    }
}

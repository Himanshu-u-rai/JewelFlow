<?php

namespace Tests\Feature\Material;

use App\Models\ShopDailyMetalRate;
use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Stage 3 — daily rates dashboard shaping.
 *
 * The rate-derivation surfaces (daily rates, purity profiles) stay gold/silver
 * only. Piece-price metals (platinum/copper) never gain a daily rate or a
 * purity profile, even when the shop has opted into selling them.
 */
class RatesDashboardVisibilityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function enableMetal(int $shopId, string $metal): void
    {
        DB::table('shop_enabled_metals')->updateOrInsert(
            ['shop_id' => $shopId, 'metal_type' => $metal],
            ['enabled' => DB::raw('TRUE'), 'updated_at' => now(), 'created_at' => now()]
        );
        MetalRegistry::clearShopCache($shopId);
    }

    private function grant(\App\Models\User $user, string ...$permissions): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($permissions as $permission) {
            $role->givePermission($permission);
        }
    }

    public function test_quick_add_purity_rejects_piece_price_metal_even_when_enabled(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grant($user, 'inventory.create');
        $this->enableMetal($shop->id, 'platinum');

        $response = $this->actingAs($user)->postJson(route('inventory.items.quick-add-purity'), [
            'metal_type' => 'platinum',
            'purity_value' => 95,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('rate-priced', $response->json('error'));

        // No platinum purity profile may exist.
        $this->assertDatabaseMissing('shop_metal_purity_profiles', [
            'shop_id' => $shop->id,
            'metal_type' => 'platinum',
        ]);
    }

    public function test_quick_add_purity_allows_gold(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grant($user, 'inventory.create');

        $response = $this->actingAs($user)->postJson(route('inventory.items.quick-add-purity'), [
            'metal_type' => 'gold',
            'purity_value' => 20,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shop_metal_purity_profiles', [
            'shop_id' => $shop->id,
            'metal_type' => 'gold',
        ]);
    }

    public function test_daily_rate_save_persists_only_gold_and_silver(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'pricing.update');

        $response = $this->actingAs($user)->post(route('settings.pricing.save-rates'), [
            'gold_24k_rate_per_gram' => 7200,
            'silver_999_rate_per_kg' => 92000,
        ]);

        $response->assertRedirect(route('settings.edit', ['tab' => 'pricing']));

        $rate = ShopDailyMetalRate::withoutTenant()->where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame(7200.0, (float) $rate->gold_24k_rate_per_gram);
        $this->assertSame(92.0, (float) $rate->silver_999_rate_per_gram);

        // The daily rate row has no platinum/copper columns — the rates
        // dashboard is structurally gold/silver only.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('shop_daily_metal_rates', 'platinum_rate_per_gram'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('shop_daily_metal_rates', 'copper_rate_per_gram'));
    }
}

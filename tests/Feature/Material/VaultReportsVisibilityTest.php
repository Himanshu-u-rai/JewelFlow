<?php

namespace Tests\Feature\Material;

use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Stage 4 — vault visibility.
 *
 * Gold/silver are primary vault lines. Opted-in Tier 2 metals (platinum/copper)
 * are tucked into a collapsible "Other materials" section that is absent for a
 * gold/silver-only shop.
 */
class VaultReportsVisibilityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grant(\App\Models\User $user, string ...$permissions): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($permissions as $permission) {
            $role->givePermission($permission);
        }
    }

    private function insertLot(int $shopId, string $metal, float $purity, float $fine): void
    {
        DB::table('metal_lots')->insert([
            'shop_id' => $shopId,
            'metal_type' => $metal,
            'source' => 'opening',
            'purity' => $purity,
            'fine_weight_total' => $fine,
            'fine_weight_remaining' => $fine,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_vault_shows_no_other_materials_section_for_gold_silver_shop(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'vault.view');
        $this->insertLot($shop->id, 'gold', 22.0, 10.0);
        $this->insertLot($shop->id, 'silver', 999.0, 50.0);

        $response = $this->actingAs($user)->get(route('vault.index'));
        $response->assertOk();
        $response->assertSee('Gold', false);
        $response->assertDontSee('Other materials', false);
    }

    public function test_vault_shows_other_materials_section_when_platinum_present(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'vault.view');
        DB::table('shop_enabled_metals')->updateOrInsert(
            ['shop_id' => $shop->id, 'metal_type' => 'platinum'],
            ['enabled' => DB::raw('TRUE'), 'updated_at' => now(), 'created_at' => now()]
        );
        MetalRegistry::clearShopCache($shop->id);

        $this->insertLot($shop->id, 'gold', 22.0, 10.0);
        $this->insertLot($shop->id, 'platinum', 95.0, 3.0);

        $response = $this->actingAs($user)->get(route('vault.index'));
        $response->assertOk();
        $response->assertSee('Other materials', false);
        $response->assertSee('platinum', false);
    }
}

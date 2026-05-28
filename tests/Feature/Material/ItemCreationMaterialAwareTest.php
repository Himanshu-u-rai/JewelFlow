<?php

namespace Tests\Feature\Material;

use App\Models\Item;
use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Stage 2 — material-aware item creation.
 *
 * Gold/silver continue through the rate-derived flow unchanged.
 * Platinum/copper use direct piece-price entry and only appear in the
 * picker when the shop has opted in.
 */
class ItemCreationMaterialAwareTest extends TestCase
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

    /**
     * The shared test tenant role has no permissions attached; grant the
     * inventory permissions the item routes require.
     */
    private function grantInventory(\App\Models\User $user): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        $role->givePermission('inventory.view');
        $role->givePermission('inventory.create');
    }

    public function test_gold_item_creation_remains_rate_derived(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grantInventory($user);

        $response = $this->actingAs($user)->post(route('inventory.items.store'), [
            'barcode' => 'MAT-GOLD-001',
            'design' => 'Gold Ring',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'stone_weight' => 1,
            'purity' => 22,
            'making_charges' => 500,
            'stone_charges' => 200,
        ]);

        $response->assertRedirect(route('inventory.items.index'));

        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'MAT-GOLD-001')->firstOrFail();
        $this->assertSame('gold', $item->metal_type);
        // Cost price derived from the daily rate (9g net × resolved 22k rate).
        $this->assertGreaterThan(0, (float) $item->cost_price);
        $this->assertSame(9.0, round((float) $item->net_metal_weight, 3));
    }

    public function test_silver_item_creation_remains_rate_derived(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grantInventory($user);

        $response = $this->actingAs($user)->post(route('inventory.items.store'), [
            'barcode' => 'MAT-SILVER-001',
            'design' => 'Silver Anklet',
            'category' => 'Silver Articles',
            'metal_type' => 'silver',
            'gross_weight' => 50,
            'stone_weight' => 0,
            'purity' => 925,
            'making_charges' => 100,
        ]);

        $response->assertRedirect(route('inventory.items.index'));

        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'MAT-SILVER-001')->firstOrFail();
        $this->assertSame('silver', $item->metal_type);
        $this->assertGreaterThan(0, (float) $item->cost_price);
    }

    public function test_platinum_item_creation_uses_direct_piece_price(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grantInventory($user);
        $this->enableMetal($shop->id, 'platinum');

        $response = $this->actingAs($user)->post(route('inventory.items.store'), [
            'barcode' => 'MAT-PLAT-001',
            'design' => 'Platinum Band',
            'category' => 'Platinum Jewellery',
            'metal_type' => 'platinum',
            'gross_weight' => 6,
            'stone_weight' => 0,
            'purity' => 95,
            'selling_price' => 85000,
        ]);

        $response->assertRedirect(route('inventory.items.index'));

        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'MAT-PLAT-001')->firstOrFail();
        $this->assertSame('platinum', $item->metal_type);
        // Selling price persisted exactly as entered — no rate derivation.
        $this->assertSame(85000.0, round((float) $item->selling_price, 2));
        // Cost price defaults to the selling price when not provided.
        $this->assertSame(85000.0, round((float) $item->cost_price, 2));
        $this->assertSame(6.0, round((float) $item->net_metal_weight, 3));
    }

    public function test_platinum_item_creation_succeeds_without_purity(): void
    {
        // P3: purity is optional for piece-price metals (platinum/copper).
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grantInventory($user);
        $this->enableMetal($shop->id, 'platinum');

        $response = $this->actingAs($user)->post(route('inventory.items.store'), [
            'barcode' => 'MAT-PLAT-NOPUR',
            'design' => 'Platinum Band',
            'category' => 'Platinum Jewellery',
            'metal_type' => 'platinum',
            'gross_weight' => 6,
            'stone_weight' => 0,
            'selling_price' => 85000,
            // no purity submitted
        ]);

        $response->assertRedirect(route('inventory.items.index'));
        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'MAT-PLAT-NOPUR')->firstOrFail();
        $this->assertSame('platinum', $item->metal_type);
        $this->assertSame(85000.0, round((float) $item->selling_price, 2));
    }

    public function test_gold_item_creation_still_requires_purity(): void
    {
        // P3: purity remains mandatory for accounting-truth metals.
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grantInventory($user);

        $response = $this->actingAs($user)->from(route('inventory.items.create'))->post(route('inventory.items.store'), [
            'barcode' => 'MAT-GOLD-NOPUR',
            'design' => 'Gold Ring',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'stone_weight' => 1,
            'making_charges' => 500,
            // no purity submitted
        ]);

        $response->assertSessionHasErrors('purity');
        $this->assertDatabaseMissing('items', ['barcode' => 'MAT-GOLD-NOPUR']);
    }

    public function test_platinum_rejected_at_backend_when_not_enabled(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grantInventory($user);
        // Platinum NOT enabled for this shop.

        $response = $this->actingAs($user)->from(route('inventory.items.create'))->post(route('inventory.items.store'), [
            'barcode' => 'MAT-PLAT-DENY',
            'design' => 'Platinum Band',
            'category' => 'Platinum Jewellery',
            'metal_type' => 'platinum',
            'gross_weight' => 6,
            'stone_weight' => 0,
            'purity' => 95,
            'selling_price' => 85000,
        ]);

        $response->assertSessionHasErrors('metal_type');
        $this->assertDatabaseMissing('items', ['barcode' => 'MAT-PLAT-DENY']);
    }

    public function test_create_page_excludes_copper_without_optin(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grantInventory($user);

        $response = $this->actingAs($user)->get(route('inventory.items.create'));
        $response->assertOk();
        $response->assertSee('value="gold"', false);
        $response->assertSee('value="silver"', false);
        $response->assertDontSee('value="copper"', false);
        $response->assertDontSee('value="platinum"', false);
    }

    public function test_create_page_includes_platinum_when_enabled(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grantInventory($user);
        $this->enableMetal($shop->id, 'platinum');

        $response = $this->actingAs($user)->get(route('inventory.items.create'));
        $response->assertOk();
        $response->assertSee('value="platinum"', false);
        // Copper still hidden — it was not opted in.
        $response->assertDontSee('value="copper"', false);
    }
}

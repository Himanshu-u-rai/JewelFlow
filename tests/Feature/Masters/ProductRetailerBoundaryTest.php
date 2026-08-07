<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\SubCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 5 — Retailer-first access boundary for Product/Design Master.
 *
 * Product/Design administration is genuinely Manufacturer-only and is already
 * server-gated at every route by Part 0.5 (edition:manufacturer + inventory.view
 * to read / catalog.manage to mutate) — see ProductEditionGateTest. Part 5 adds
 * ZERO behavior change; these tests lock in the two guarantees that were not yet
 * covered, framed around the retailer-first priority:
 *
 *   1. A read-only shop that legitimately HOLDS the manufacturer entitlement can
 *      still READ products but cannot MUTATE them (subscription.active guard).
 *   2. The Retailer transactional Item-create flow is INDEPENDENT of the Product
 *      master: the "Quick Fill from Product Catalog" selector self-hides for a
 *      retailer (who owns no Product rows), retailer item creation is unaffected,
 *      and retailer Items reference no Product. The shared screen adapts by data,
 *      so no over-gating of the retailer flow is needed or wanted.
 */
class ProductRetailerBoundaryTest extends TestCase
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

    private function makeProduct(int $shopId): Product
    {
        $category = Category::forceCreate(['shop_id' => $shopId, 'name' => 'Rings']);
        $subCategory = SubCategory::forceCreate(['shop_id' => $shopId, 'category_id' => $category->id, 'name' => 'Plain']);

        return Product::forceCreate([
            'shop_id' => $shopId,
            'name' => 'Bridal Set',
            'design_code' => 'PRD-' . uniqid(),
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
        ]);
    }

    // ---- Gap 1: read-only shop reads but cannot mutate products -----------

    public function test_read_only_manufacturer_shop_can_read_but_never_mutate_products(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->grant($user, 'inventory.view', 'catalog.manage');
        $product = $this->makeProduct($shop->id);
        $this->actingAs($user);

        // Read is allowed even in read-only mode.
        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.show', $product))->assertOk();

        // Downgrade to read-only: every non-GET must be refused and leave the row
        // untouched. Re-act with a fresh user instance so the guard reloads the
        // shop relation (in production every request loads it fresh anyway).
        $shop->forceFill(['access_mode' => 'read_only'])->save();
        $this->actingAs($user->fresh());

        $this->post(route('products.store'), [
            'name' => 'Should Not Persist',
            'category_id' => $product->category_id,
            'sub_category_id' => $product->sub_category_id,
            'metal_type' => 'gold',
        ]);
        $this->put(route('products.update', $product), [
            'name' => 'Renamed In Read Only',
            'category_id' => $product->category_id,
            'sub_category_id' => $product->sub_category_id,
            'metal_type' => 'gold',
        ]);
        $this->delete(route('products.destroy', $product));

        // No create, no rename, no delete happened.
        $this->assertSame(1, Product::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->assertSame('Bridal Set', Product::withoutTenant()->findOrFail($product->id)->name);
    }

    // ---- Gap 2: retailer transactional Item flow is independent of Product --

    public function test_retailer_item_create_hides_the_product_catalog_selector(): void
    {
        // A retailer owns no Product rows, so the manufacturer-oriented
        // "Quick Fill from Product Catalog" block self-hides. The retailer never
        // depends on the Product master to create stock.
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grant($user, 'inventory.view', 'inventory.create');
        $this->actingAs($user);

        $this->get(route('inventory.items.create'))
            ->assertOk()
            ->assertDontSee('Quick Fill from Product Catalog');
    }

    public function test_manufacturer_item_create_shows_the_product_catalog_selector(): void
    {
        // Contrast: the SAME shared screen surfaces the selector for a
        // manufacturer that has Product rows — proving item-create adapts by data
        // rather than needing an edition hard-gate that would harm retailers.
        [$user, $shop] = $this->createManufacturerTenant();
        $this->grant($user, 'inventory.view', 'inventory.create');
        $this->makeProduct($shop->id);
        $this->actingAs($user);

        $this->get(route('inventory.items.create'))
            ->assertOk()
            ->assertSee('Quick Fill from Product Catalog');
    }

    public function test_retailer_created_item_references_no_product(): void
    {
        // Data-level proof that retailer transactional use is distinct from the
        // Product master: a retailer-created Item carries a null product_id.
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grant($user, 'inventory.view', 'inventory.create');

        $this->actingAs($user)->post(route('inventory.items.store'), [
            'barcode' => 'P5-RETAIL-001',
            'design' => 'Gold Bangle',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 12,
            'stone_weight' => 0,
            'purity' => 22,
            'making_charges' => 400,
            'stone_charges' => 0,
        ])->assertRedirect(route('inventory.items.index'));

        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'P5-RETAIL-001')->firstOrFail();
        $this->assertNull($item->product_id);
    }
}

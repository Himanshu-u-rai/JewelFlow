<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SubCategory;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 0.5 — server-side Manufacturer edition gate on Product routes.
 *
 * Product/Design Master is Manufacturer-only. Reuses the existing
 * `edition:manufacturer` middleware (EnsureShopEdition, backed by
 * Shop::hasAnyEdition()) rather than gating on shop_type, so a multi-edition
 * shop that merely holds the manufacturer entitlement is still let through.
 */
class ProductEditionGateTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function dhiranTenant(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('dhiran');
        $shop = $this->createShop('dhiran');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $this->createSubscription($shop->id, $admin, $plan);
        $this->createBillingSettings($shop->id);
        $this->markShopOpeningSetupComplete($shop->id);

        return [$user, $shop];
    }

    public function test_manufacturer_shop_can_access_product_routes(): void
    {
        [$user] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.create'))->assertOk();
    }

    public function test_retailer_only_shop_cannot_direct_hit_product_routes(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->actingAs($user);

        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('products.create'))->assertForbidden();
    }

    public function test_dhiran_only_context_cannot_access_product_routes(): void
    {
        [$user] = $this->dhiranTenant();
        $this->actingAs($user);

        $this->get(route('products.index'))->assertForbidden();
    }

    public function test_multi_edition_shop_with_manufacturer_entitlement_is_allowed(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        ShopEdition::grantTo($shop, ShopEdition::MANUFACTURER);
        $this->actingAs($user);

        $this->get(route('products.index'))->assertOk();
    }

    public function test_permissions_are_still_enforced_alongside_edition_access(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        // Strip every permission from this user's role — the manufacturer edition
        // alone must not be enough; catalog.manage/inventory.view still gate it.
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        $role->permissions()->sync([]);
        $this->actingAs($user);

        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('products.create'))->assertForbidden();
    }

    /*
     * PART 0.5 CLOSURE — Phase D. routes/web.php pairs every Product route with
     * both edition:manufacturer and a permission gate (inventory.view for
     * index/show, catalog.manage for create/store/edit/update/destroy). The
     * tests above only exercised index/create; the tests below extend the same
     * scenarios (authorized, retail-only, dhiran-only, multi-edition,
     * permission-stripped, cross-shop, missing) across every action, via real
     * HTTP requests rather than inspecting middleware strings.
     */

    private function makeProduct(int $shopId): Product
    {
        $category = Category::forceCreate(['shop_id' => $shopId, 'name' => 'Rings']);
        $subCategory = SubCategory::forceCreate(['shop_id' => $shopId, 'category_id' => $category->id, 'name' => 'Plain']);

        return Product::forceCreate([
            'shop_id' => $shopId,
            'name' => 'Test Product',
            'design_code' => 'PRD-' . uniqid(),
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
        ]);
    }

    private function storePayload(Product $product): array
    {
        return [
            'name' => 'New Design',
            'category_id' => $product->category_id,
            'sub_category_id' => $product->sub_category_id,
            'metal_type' => 'gold',
        ];
    }

    public function test_manufacturer_owner_can_perform_every_product_action(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $product = $this->makeProduct($shop->id);

        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.create'))->assertOk();
        $this->get(route('products.show', $product))->assertOk();
        $this->get(route('products.edit', $product))->assertOk();
        $this->put(route('products.update', $product), $this->storePayload($product))->assertRedirect(route('products.show', $product));
        $this->post(route('products.store'), $this->storePayload($product))->assertRedirect();
        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'));
    }

    public function test_manufacturer_without_catalog_manage_is_forbidden_from_mutating_actions(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $product = $this->makeProduct($shop->id);
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        $role->permissions()->sync(\App\Models\Permission::where('name', 'inventory.view')->pluck('id'));
        $this->actingAs($user);

        // inventory.view is still granted, so read actions succeed.
        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.show', $product))->assertOk();

        // catalog.manage was stripped, so every mutating action is forbidden.
        $this->get(route('products.create'))->assertForbidden();
        $this->post(route('products.store'), $this->storePayload($product))->assertForbidden();
        $this->get(route('products.edit', $product))->assertForbidden();
        $this->put(route('products.update', $product), $this->storePayload($product))->assertForbidden();
        $this->delete(route('products.destroy', $product))->assertForbidden();
    }

    public function test_manufacturer_without_inventory_view_is_forbidden_from_read_actions(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $product = $this->makeProduct($shop->id);
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        $role->permissions()->sync(\App\Models\Permission::where('name', 'catalog.manage')->pluck('id'));
        $this->actingAs($user);

        // inventory.view was stripped, so read actions are forbidden...
        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('products.show', $product))->assertForbidden();

        // ...but catalog.manage is still granted, so mutating actions succeed.
        $this->get(route('products.create'))->assertOk();
        $this->get(route('products.edit', $product))->assertOk();
        $this->put(route('products.update', $product), $this->storePayload($product))->assertRedirect(route('products.show', $product));
    }

    public function test_retailer_only_shop_cannot_direct_hit_any_product_action(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $product = $this->makeProduct($shop->id);
        $this->actingAs($user);

        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('products.create'))->assertForbidden();
        $this->post(route('products.store'), $this->storePayload($product))->assertForbidden();
        $this->get(route('products.show', $product))->assertForbidden();
        $this->get(route('products.edit', $product))->assertForbidden();
        $this->put(route('products.update', $product), $this->storePayload($product))->assertForbidden();
        $this->delete(route('products.destroy', $product))->assertForbidden();
    }

    public function test_dhiran_only_context_cannot_access_any_product_action(): void
    {
        [$user, $shop] = $this->dhiranTenant();
        $product = $this->makeProduct($shop->id);
        $this->actingAs($user);

        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('products.create'))->assertForbidden();
        $this->post(route('products.store'), $this->storePayload($product))->assertForbidden();
        $this->get(route('products.show', $product))->assertForbidden();
        $this->get(route('products.edit', $product))->assertForbidden();
        $this->put(route('products.update', $product), $this->storePayload($product))->assertForbidden();
        $this->delete(route('products.destroy', $product))->assertForbidden();
    }

    public function test_multi_edition_shop_with_manufacturer_entitlement_is_allowed_for_every_action(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        ShopEdition::grantTo($shop, ShopEdition::MANUFACTURER);
        $product = $this->makeProduct($shop->id);
        $this->actingAs($user);

        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.create'))->assertOk();
        $this->get(route('products.show', $product))->assertOk();
        $this->get(route('products.edit', $product))->assertOk();
        $this->put(route('products.update', $product), $this->storePayload($product))->assertRedirect(route('products.show', $product));
    }

    public function test_cross_shop_product_id_is_inaccessible_via_any_action(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $productB = $this->makeProduct($shopB->id);
        $this->actingAs($userA);

        $this->get(route('products.show', $productB))->assertNotFound();
        $this->get(route('products.edit', $productB))->assertNotFound();
        $this->put(route('products.update', $productB), $this->storePayload($productB))->assertNotFound();
        $this->delete(route('products.destroy', $productB))->assertNotFound();
    }

    public function test_missing_product_id_is_inaccessible_via_any_action(): void
    {
        [$user] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->get('/products/999999')->assertNotFound();
        $this->get('/products/999999/edit')->assertNotFound();
        $this->put('/products/999999', [])->assertNotFound();
        $this->delete('/products/999999')->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 0.5 CLOSURE — Phase E. Mobile category contract regression.
 *
 * Part 0.5 touched only CategoryController/ProductController (web). This
 * file proves the two mobile "categories" endpoints are untouched and behave
 * exactly as already implemented — no mobile controller/route/payload code
 * was changed to make these tests pass.
 *
 * The two endpoints are NOT the same shape, and the task's assumption that
 * "each category object retains exactly {id, name, slug}" only holds for
 * ONE of them:
 *
 *  - GET /api/mobile/categories (ItemController::categories) returns a bare
 *    JSON array of Category rows, `select('id','name','slug')` — genuinely
 *    {id, name, slug} objects, implicitly tenant-scoped by Category's
 *    BelongsToShop global scope.
 *  - GET /api/mobile/catalog/categories (CatalogController::categories)
 *    returns `{"data": [...]}` where each element is a plain distinct
 *    Item.category STRING (legacy free-text category names off in-stock
 *    Items), explicitly filtered by shop_id — not {id,name,slug} objects at
 *    all. This is the real, pre-existing contract; asserted as-is here
 *    rather than forced to match the other shape.
 */
class MobileCategoryContractTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_items_categories_endpoint_returns_id_name_slug_objects(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings']);
        Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Bangles']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/categories');

        $response->assertOk();
        $names = collect($response->json())->pluck('name')->sort()->values()->all();
        $this->assertSame(['Bangles', 'Rings'], $names);
        foreach ($response->json() as $category) {
            $this->assertSame(['id', 'name', 'slug'], array_keys($category));
        }
    }

    public function test_items_categories_endpoint_does_not_leak_cross_shop_categories(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        Category::forceCreate(['shop_id' => $shopA->id, 'name' => 'ShopA Cat']);
        Category::forceCreate(['shop_id' => $shopB->id, 'name' => 'ShopB Cat']);
        Sanctum::actingAs($userA);

        $names = collect($this->getJson('/api/mobile/categories')->json())->pluck('name')->all();

        $this->assertContains('ShopA Cat', $names);
        $this->assertNotContains('ShopB Cat', $names);
    }

    public function test_catalog_categories_endpoint_returns_data_envelope_of_item_category_strings(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->createItem($shop->id, null, ['category' => 'Ring']);
        $this->createItem($shop->id, null, ['category' => 'Necklace']);
        // status != in_stock must be excluded — same filter the controller applies.
        $this->createItem($shop->id, null, ['category' => 'Sold Category', 'status' => 'sold']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/catalog/categories');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        sort($data);
        $this->assertSame(['Necklace', 'Ring'], $data);
        // Every element is a plain string, not an {id,name,slug} object.
        foreach ($data as $entry) {
            $this->assertIsString($entry);
        }
    }

    public function test_catalog_categories_endpoint_does_not_leak_cross_shop_items(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $this->createItem($shopA->id, null, ['category' => 'ShopA Item Category']);
        $this->createItem($shopB->id, null, ['category' => 'ShopB Item Category']);
        Sanctum::actingAs($userA);

        $data = $this->getJson('/api/mobile/catalog/categories')->json('data');

        $this->assertContains('ShopA Item Category', $data);
        $this->assertNotContains('ShopB Item Category', $data);
    }

    /**
     * These routes are unnamed (matched by URI), so "unchanged" is proven by
     * resolving them directly — Router::match() throws NotFoundHttpException
     * if the URI/verb pair no longer exists.
     */
    public function test_established_mobile_category_routes_are_unchanged(): void
    {
        $routes = app('router')->getRoutes();

        $itemsRoute = $routes->match(\Illuminate\Http\Request::create('/api/mobile/categories', 'GET'));
        $this->assertSame('App\Http\Controllers\Api\Mobile\ItemController@categories', $itemsRoute->getActionName());

        $catalogRoute = $routes->match(\Illuminate\Http\Request::create('/api/mobile/catalog/categories', 'GET'));
        $this->assertSame('App\Http\Controllers\Api\Mobile\CatalogController@categories', $catalogRoute->getActionName());
    }
}

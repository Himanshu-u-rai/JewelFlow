<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 0.5 — Product (Design Master) delete guard.
 *
 * ProductController::destroy() used to delete unconditionally, relying on the
 * DB's items.product_id ON DELETE SET NULL to silently detach any Items. This
 * locks in the new app-level guard: a Product with Items still attached must
 * be blocked, not silently detached.
 */
class ProductDeleteGuardTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeProduct(int $shopId): Product
    {
        // Note: shop_id isn't fillable on these models (BelongsToShop's
        // auth-based auto-fill covers normal requests) and the auto-fill only
        // fires outside app()->runningInConsole() — true for the whole
        // `php artisan test` process — so forceCreate() is used to set it
        // explicitly, same as CreatesTestTenant::createItem() does.
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

    public function test_unused_product_deletes_successfully(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $product = $this->makeProduct($shop->id);

        $this->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_product_referenced_by_item_is_blocked(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $product = $this->makeProduct($shop->id);
        $item = $this->createItem($shop->id, null, ['product_id' => $product->id]);

        $this->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('items', ['id' => $item->id, 'product_id' => $product->id]);
    }

    public function test_blocked_deletion_preserves_item_product_id(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $product = $this->makeProduct($shop->id);
        $item = $this->createItem($shop->id, null, ['product_id' => $product->id]);

        $this->delete(route('products.destroy', $product));

        $item->refresh();
        $this->assertSame($product->id, $item->product_id);
    }

    public function test_cross_shop_product_deletion_is_blocked(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [$userB, $shopB] = $this->createManufacturerTenant();

        $this->actingAs($userB);
        $productB = $this->makeProduct($shopB->id);

        $this->actingAs($userA);
        $this->delete(route('products.destroy', $productB))->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $productB->id]);
    }

    public function test_missing_product_remains_404(): void
    {
        [$user] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->delete('/products/999999')->assertNotFound();
    }

    /**
     * Previously the schema let an Item's product_id point at another shop's
     * Product (no FK existed), so this test used to build that malformed row and
     * prove the guard still counted it. The composite FK from migration
     * 2026_09_07_000000 — items(product_id, shop_id) -> products(id, shop_id) —
     * now makes that row physically impossible: the insert is rejected by the DB
     * (SQLSTATE 23503) because there is no products(id, shop_id) matching the
     * cross-shop pair. So instead of bypassing the constraint, assert the DB
     * refuses the cross-shop link outright.
     */
    public function test_cross_shop_item_product_link_is_rejected_by_database(): void
    {
        [, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();

        $product = $this->makeProduct($shopA->id);

        // Postgres aborts the whole transaction on the FK violation, so no further
        // query can run afterward under RefreshDatabase's wrapping transaction —
        // the thrown 23503 is itself proof the cross-shop row never persisted.
        try {
            $this->createItem($shopB->id, null, ['product_id' => $product->id]);
            $this->fail('Expected the composite FK to reject an Item linking to another shop\'s Product.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }
    }

    /**
     * PART 0.5 CLOSURE — Phase C repeatability. Same reasoning as
     * CategoryDeleteGuardTest::test_blocked_category_deletion_is_repeatable:
     * a blocked delete is a pure count-then-redirect read, so firing it
     * twice must leave the Item link untouched both times.
     */
    public function test_blocked_product_deletion_is_repeatable(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $product = $this->makeProduct($shop->id);
        $item = $this->createItem($shop->id, null, ['product_id' => $product->id]);

        $this->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error');

        $this->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $item->refresh();
        $this->assertSame($product->id, $item->product_id);
    }

    /**
     * Successful-delete counterpart to test_missing_product_remains_404:
     * once the Product is actually gone, replaying the same delete request
     * must 404, not silently succeed or 500.
     */
    public function test_repeat_request_after_successful_product_deletion_is_404(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $product = $this->makeProduct($shop->id);

        $this->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');

        $this->delete(route('products.destroy', $product))->assertNotFound();
    }

    /**
     * The count-then-block message must reflect the actual Item count (3
     * here), proving the count() call isn't hardcoded or capped at 1.
     */
    public function test_product_dependency_message_reports_exact_count(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $product = $this->makeProduct($shop->id);
        $this->createItem($shop->id, null, ['product_id' => $product->id]);
        $this->createItem($shop->id, null, ['product_id' => $product->id]);
        $this->createItem($shop->id, null, ['product_id' => $product->id]);

        $this->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error');

        $message = strtolower(session('error'));
        $this->assertStringContainsString('3 items', $message);
    }

    /**
     * FINAL EVIDENCE SUPPLEMENT — visible UX proof. Follows the redirect so
     * layouts/app.blade.php actually renders, and asserts the Item dependency
     * count appears in the rendered <meta name="flash-error"> (the app's flash
     * convention), that no raw DB error leaks, and the page is a 200 — not a
     * 500 from an uncaught items.product_id SET NULL FK path.
     */
    public function test_blocked_product_page_visibly_shows_item_count(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $product = $this->makeProduct($shop->id);
        $this->createItem($shop->id, null, ['product_id' => $product->id]);
        $this->createItem($shop->id, null, ['product_id' => $product->id]);

        $response = $this->followingRedirects()->delete(route('products.destroy', $product));

        $response->assertOk();
        $response->assertSee('2 items', false);
        $response->assertDontSee('SQLSTATE', false);
        $response->assertDontSee('QueryException', false);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /**
     * Product-side counterpart to
     * CategoryDeleteGuardTest::test_blocked_deletion_changes_no_records —
     * a blocked delete must leave every row count in the tenant untouched,
     * not just the one Item asserted elsewhere.
     */
    public function test_blocked_product_deletion_changes_no_records(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $product = $this->makeProduct($shop->id);
        $this->createItem($shop->id, null, ['product_id' => $product->id]);

        $countsBefore = [Product::count(), \App\Models\Item::count()];

        $this->delete(route('products.destroy', $product));

        $countsAfter = [Product::count(), \App\Models\Item::count()];

        $this->assertSame($countsBefore, $countsAfter);
    }
}

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
}

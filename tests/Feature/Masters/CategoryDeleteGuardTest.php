<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 0.5 — Category delete guard.
 *
 * CategoryController::destroy() used to unconditionally cascade-delete
 * subcategories then delete the category, and had no Product dependency
 * check at all (a Product referencing the category would hit the DB's
 * restrict FK and 500). This locks in the new check-first, block-with-count
 * behaviour instead.
 *
 * Two console-only quirks of BelongsToShop's tenant scope show up under
 * `php artisan test` (never in real HTTP traffic) and are worked around here:
 *
 *  - shop_id isn't mass-assignable (BelongsToShop's own creating-hook fills
 *    it from auth), and that auth-based fallback is itself skipped whenever
 *    app()->runningInConsole() is true — which it is for the whole test
 *    process. forceCreate() sets shop_id explicitly instead.
 *  - {category} is an implicit (type-hinted) route-model binding, which
 *    Laravel resolves via Illuminate\Routing\Middleware\SubstituteBindings —
 *    and that middleware runs BEFORE app's own EnsureTenantUser in the
 *    configured priority (bootstrap/app.php), so TenantContext::get() is
 *    still null at binding time. In real requests this is masked by
 *    runningInConsole() being false there, so BelongsToShop falls back to
 *    Auth::user()->shop_id anyway; under the console test process that
 *    fallback is unavailable too, so binding would 404 every category
 *    regardless of tenant. TenantContext::runFor() sets the context
 *    explicitly for the request, matching what EnsureTenantUser does for
 *    real traffic.
 */
class CategoryDeleteGuardTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeCategory(int $shopId, string $name): Category
    {
        return Category::forceCreate(['shop_id' => $shopId, 'name' => $name]);
    }

    private function makeSubCategory(int $shopId, int $categoryId, string $name): SubCategory
    {
        return SubCategory::forceCreate(['shop_id' => $shopId, 'category_id' => $categoryId, 'name' => $name]);
    }

    private function makeProduct(int $shopId, int $categoryId, int $subCategoryId): Product
    {
        return Product::forceCreate([
            'shop_id' => $shopId,
            'name' => 'Test Product',
            'design_code' => 'PRD-' . uniqid(),
            'category_id' => $categoryId,
            'sub_category_id' => $subCategoryId,
        ]);
    }

    private function destroyAs(int $shopId, Category $category)
    {
        return TenantContext::runFor($shopId, fn () => $this->delete(route('categories.destroy', $category)));
    }

    public function test_unused_category_deletes_successfully(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $category = $this->makeCategory($shop->id, 'Rings');

        $this->destroyAs($shop->id, $category)
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_category_with_product_dependency_is_blocked(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $category = $this->makeCategory($shop->id, 'Rings');
        // sub_category_id is a required, restrict FK on products — point it at an
        // unrelated subcategory so this test isolates the Product dependency only.
        $otherCategory = $this->makeCategory($shop->id, 'Necklaces');
        $otherSub = $this->makeSubCategory($shop->id, $otherCategory->id, 'Plain');
        $product = $this->makeProduct($shop->id, $category->id, $otherSub->id);

        $this->destroyAs($shop->id, $category)
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => $category->id]);
    }

    public function test_category_with_subcategory_dependency_is_blocked(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $category = $this->makeCategory($shop->id, 'Rings');
        $subCategory = $this->makeSubCategory($shop->id, $category->id, 'Plain');

        $this->destroyAs($shop->id, $category)
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('sub_categories', ['id' => $subCategory->id]);
    }

    public function test_category_with_both_dependencies_reports_both(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $category = $this->makeCategory($shop->id, 'Rings');
        $subCategory = $this->makeSubCategory($shop->id, $category->id, 'Plain');
        $this->makeProduct($shop->id, $category->id, $subCategory->id);

        $response = TenantContext::runFor(
            $shop->id,
            fn () => $this->from(route('categories.index'))->delete(route('categories.destroy', $category))
        );

        $response->assertRedirect(route('categories.index'));
        $message = strtolower(session('error'));
        $this->assertStringContainsString('product', $message);
        $this->assertStringContainsString('subcategor', $message);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('sub_categories', ['id' => $subCategory->id]);
    }

    public function test_blocked_deletion_changes_no_records(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $category = $this->makeCategory($shop->id, 'Rings');
        $subCategory = $this->makeSubCategory($shop->id, $category->id, 'Plain');
        $product = $this->makeProduct($shop->id, $category->id, $subCategory->id);

        $countsBefore = TenantContext::runFor($shop->id, fn () => [
            Category::count(),
            SubCategory::count(),
            Product::count(),
        ]);

        $this->destroyAs($shop->id, $category);

        $countsAfter = TenantContext::runFor($shop->id, fn () => [
            Category::count(),
            SubCategory::count(),
            Product::count(),
        ]);

        $this->assertSame($countsBefore, $countsAfter);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_cross_shop_category_deletion_is_blocked(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [$userB, $shopB] = $this->createManufacturerTenant();

        $categoryB = $this->makeCategory($shopB->id, 'Bangles');

        $this->actingAs($userA);
        // Real tenant context is shop A's — shop B's category must stay invisible.
        $this->destroyAs($shopA->id, $categoryB)->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $categoryB->id]);
    }

    public function test_missing_category_remains_404(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        TenantContext::runFor($shop->id, fn () => $this->delete('/categories/999999'))->assertNotFound();
    }
}

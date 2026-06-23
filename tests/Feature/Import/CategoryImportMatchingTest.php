<?php

namespace Tests\Feature\Import;

use App\Models\Category;
use App\Models\Import;
use App\Models\Shop;
use App\Models\User;
use App\Services\BulkImportService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Bulk import category matching (Module 22). Pins the market-ready requirement:
 * during a catalog import the category must be matched shop-scoped + trimmed +
 * space-collapsed + case-insensitive, reusing the existing category (preserving
 * its display name) rather than creating a casing/spacing duplicate. Backed by
 * Category::normalizeName (trim + mb_strtolower + collapse \s+) and the DB
 * `categories_shop_normalized_unique (shop_id, normalized_name)` index.
 */
class CategoryImportMatchingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** Run a one-row catalog import for ($category, $sub) and execute it. */
    private function importCatalogRow(Shop $shop, User $user, string $category, string $sub): void
    {
        $service = app(BulkImportService::class);
        $csv = UploadedFile::fake()->createWithContent('cat.csv', implode("\n", [
            'design_code,name,category,sub_category,default_purity,approx_weight,default_making,stone_type,notes',
            'D-1,Some Ring,' . $category . ',' . $sub . ',22,10,500,None,n',
        ]));

        TenantContext::runFor($shop->id, function () use ($service, $shop, $user, $csv) {
            $import = $service->createPreview($shop->id, $user->id, Import::TYPE_CATALOG, $csv);
            $service->execute($import->fresh(), Import::MODE_STRICT);
        });
    }

    private function catCount(Shop $shop, string $normalized): int
    {
        return Category::withoutTenant()->where('shop_id', $shop->id)->where('normalized_name', $normalized)->count();
    }

    public function test_lowercase_csv_reuses_existing_titlecase_category(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor($shop->id, fn () => Category::create(['name' => 'Gold']));

        $this->importCatalogRow($shop, $user, 'gold', 'Rings');

        $this->assertSame(1, $this->catCount($shop, 'gold'), 'lowercase "gold" must reuse existing "Gold"');
        $this->assertSame('Gold', Category::withoutTenant()->where('shop_id', $shop->id)->where('normalized_name', 'gold')->value('name'),
            'existing display name "Gold" preserved');
    }

    public function test_diamond_lowercase_reuses_existing(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor($shop->id, fn () => Category::create(['name' => 'Diamond']));

        $this->importCatalogRow($shop, $user, 'diamond', 'Studs');

        $this->assertSame(1, $this->catCount($shop, 'diamond'));
    }

    public function test_extra_whitespace_csv_reuses_existing_category(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor($shop->id, fn () => Category::create(['name' => 'Gold Jewellery']));

        // Leading/trailing + repeated internal spaces must collapse to the same key.
        $this->importCatalogRow($shop, $user, '  gold   jewellery  ', 'Rings');

        $this->assertSame(1, $this->catCount($shop, 'gold jewellery'), 'whitespace variants must reuse, not duplicate');
        $this->assertSame('Gold Jewellery', Category::withoutTenant()->where('shop_id', $shop->id)->where('normalized_name', 'gold jewellery')->value('name'));
    }

    public function test_same_name_in_another_shop_is_not_reused(): void
    {
        [, $shopB] = $this->createManufacturerTenant();
        TenantContext::runFor($shopB->id, fn () => Category::create(['name' => 'Gold']));

        [$userA, $shopA] = $this->createManufacturerTenant();
        $this->importCatalogRow($shopA, $userA, 'gold', 'Rings');

        // Each shop owns its own "Gold"; the import created shop A's, never reusing shop B's.
        $this->assertSame(1, $this->catCount($shopA, 'gold'), 'shop A gets its own Gold');
        $this->assertSame(1, $this->catCount($shopB, 'gold'), 'shop B Gold untouched');
        $this->assertNotSame(
            Category::withoutTenant()->where('shop_id', $shopA->id)->where('normalized_name', 'gold')->value('id'),
            Category::withoutTenant()->where('shop_id', $shopB->id)->where('normalized_name', 'gold')->value('id'),
            'cross-shop categories are distinct rows'
        );
    }

    public function test_genuinely_new_category_is_created_once(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->importCatalogRow($shop, $user, 'Platinum', 'Bands');

        $this->assertSame(1, $this->catCount($shop, 'platinum'), 'new category auto-created exactly once');
    }
}

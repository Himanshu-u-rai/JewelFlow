<?php

namespace Tests\Feature\Catalog;

use App\Models\CatalogWebsiteSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The public shop website must show each in-stock item on exactly one page,
 * even when items share a created_at (newest sort) or a price (price sort).
 */
class PublicCatalogPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    private const ERP = 'https://jewelflows.com';
    private const PER_PAGE = 24;

    /** @return array{0: string, 1: array<int, int>} slug and item ids */
    private function storefront(array $tied): array
    {
        $this->skipIfNotPostgres();
        [, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $shop->forceFill(['catalog_slug' => 'shop-' . $shop->id])->save();
            CatalogWebsiteSettings::updateOrCreate(['shop_id' => $shop->id], ['is_enabled' => true]);
        });
        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, 55))
            ->map(fn ($n) => $this->createItem($shop->id, null, ['design' => 'Same second ' . $n])->id)->all());
        $this->tieAndScramble('items', $ids, $tied);

        return ['shop-' . $shop->id, $ids];
    }

    private function assertPublic(string $path, array $tied, string $query = ''): void
    {
        [$slug, $ids] = $this->storefront($tied);
        $page = fn (int $p) => $this->get(self::ERP . '/s/' . $slug . $path . '?page=' . $p . $query)
            ->assertOk()->viewData('items')->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, self::PER_PAGE, $path);
        $this->assertSame(array_slice(array_reverse($ids), 0, self::PER_PAGE), $page(1));
    }

    public function test_products_newest_sort(): void
    {
        $this->assertPublic('/products', ['created_at' => '2026-11-01 10:00:00']);
    }

    public function test_products_price_sort_with_equal_prices(): void
    {
        $this->assertPublic('/products', ['selling_price' => 40000], '&sort=price_desc');
    }

    public function test_category_newest_sort(): void
    {
        $this->assertPublic('/category/Ring', ['created_at' => '2026-11-01 10:00:00']);
    }
}

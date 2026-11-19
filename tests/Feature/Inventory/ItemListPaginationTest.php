<?php

namespace Tests\Feature\Inventory;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Items created in the same second (bulk import, tag run) must each show on
 * exactly one page of every item list, newest (highest id) first.
 */
class ItemListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    private const PER_PAGE = 20;

    /** @return array{0: \App\Models\User, 1: \App\Models\Shop, 2: array<int, int>} */
    private function tiedItems(int $count = 55): array
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();
        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, $count))
            ->map(fn ($n) => $this->createItem($shop->id, null, ['design' => 'Same second ' . $n])->id)->all());
        $this->tieAndScramble('items', $ids, ['created_at' => '2026-11-01 10:00:00']);

        return [$owner, $shop, $ids];
    }

    private function assertWebList(string $url, string $var): void
    {
        [$owner, $shop, $ids] = $this->tiedItems();
        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get($url . '?page=' . $p)->assertOk()->viewData($var))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, self::PER_PAGE, $url);
        $this->assertSame(array_slice(array_reverse($ids), 0, self::PER_PAGE), $page(1));
    }

    public function test_inventory_items_list(): void
    {
        $this->assertWebList('/inventory/items', 'items');
    }

    public function test_tag_print_list(): void
    {
        $this->assertWebList('/tags', 'items');
    }

    public function test_catalog_list(): void
    {
        $this->assertWebList('/catalog', 'items');
    }

    public function test_mobile_stock_list(): void
    {
        [$owner, $shop, $ids] = $this->tiedItems();
        Sanctum::actingAs($owner, ['*']);
        $page = fn (int $p) => collect($this->getJson('/api/mobile/stock?per_page=15&page=' . $p)
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'mobile stock');
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }

    public function test_mobile_catalog_list(): void
    {
        [$owner, $shop, $ids] = $this->tiedItems();
        Sanctum::actingAs($owner, ['*']);
        $page = fn (int $p) => collect($this->getJson('/api/mobile/catalog/items?per_page=15&page=' . $p)
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'mobile catalog');
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }
}

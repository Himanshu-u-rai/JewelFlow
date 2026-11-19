<?php

namespace Tests\Feature\Vendors;

use App\Models\Vendor;
use App\Support\TenantContext;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Vendors added in the same second (an import, a busy setup day) must each
 * show on exactly one page of the vendor list.
 */
class VendorListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    public function test_vendors_with_the_same_created_at_each_appear_on_exactly_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();

        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, 45))
            ->map(fn ($n) => Vendor::create(['name' => 'Same second supplier ' . $n])->id)->all());
        $this->tieAndScramble('vendors', $ids, ['created_at' => '2026-11-01 10:00:00']);

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/vendors?page=' . $p)->assertOk()->viewData('vendors'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'web vendors');
        // Newest first: among equal times, the later-created (higher id) vendor leads.
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Shop, 2: array<int, int>} */
    private function tiedVendors(): array
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();
        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, 45))
            ->map(fn ($n) => Vendor::create(['name' => 'Same second supplier ' . $n])->id)->all());
        $this->tieAndScramble('vendors', $ids, ['created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00', 'name' => 'Same name'], 'notes');

        return [$owner, $shop, $ids];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mobileSorts')]
    public function test_mobile_vendor_list_each_appear_on_exactly_one_page(string $sort): void
    {
        [$owner, , $ids] = $this->tiedVendors();
        Sanctum::actingAs($owner, ['*']);
        $page = fn (int $p) => collect($this->getJson('/api/mobile/vendors?per_page=15&sort=' . $sort . '&page=' . $p)
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'mobile vendors ' . $sort);
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }

    public static function mobileSorts(): array
    {
        return ['default' => ['recent'], 'name' => ['name'], 'updated' => ['updated']];
    }

    public function test_mobile_vendor_ledger_items_each_appear_on_exactly_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();
        $vendorId = TenantContext::runFor($shop->id, fn () => Vendor::create(['name' => 'Ledger supplier'])->id);
        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, 45))
            ->map(fn ($n) => $this->createItem($shop->id, null, ['vendor_id' => $vendorId])->id)->all());
        $this->tieAndScramble('items', $ids, ['created_at' => '2026-11-01 10:00:00']);
        Sanctum::actingAs($owner, ['*']);

        $page = fn (int $p) => collect($this->getJson("/api/mobile/vendors/{$vendorId}/ledger?per_page=15&page=" . $p)
            ->assertOk()->json('entries'))->pluck('reference_id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'mobile vendor ledger');
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }
}

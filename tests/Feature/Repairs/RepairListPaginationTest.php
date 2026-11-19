<?php

namespace Tests\Feature\Repairs;

use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Repairs taken in the same second must each show on exactly one page of the
 * repair report and of the mobile repair list.
 */
class RepairListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\Shop, 2: array<int, int>} */
    private function shopWithTiedRepairs(int $count): array
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, $count))->map(fn ($n) => Repair::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'item_description' => 'Chain ' . $n,
            'description' => 'Clasp', 'gross_weight' => 8.5, 'estimated_cost' => 450, 'status' => 'received',
        ])->id)->all());
        $this->tieAndScramble('repairs', $ids, ['created_at' => '2026-11-01 10:00:00']);

        return [$owner, $shop, $ids];
    }

    public function test_report_repairs_with_the_same_created_at_each_appear_on_exactly_one_page(): void
    {
        [$owner, $shop, $ids] = $this->shopWithTiedRepairs(60);

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/report/repairs?page=' . $p)->assertOk()->viewData('repairs'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 25, 'repair report');
        $this->assertSame(array_slice(array_reverse($ids), 0, 25), $page(1));
    }

    public function test_mobile_repairs_with_the_same_created_at_each_appear_on_exactly_one_page(): void
    {
        [$owner, $shop, $ids] = $this->shopWithTiedRepairs(50);
        Sanctum::actingAs($owner);

        $page = fn (int $p) => collect($this->getJson('/api/mobile/repairs?per_page=20&page=' . $p)
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 20, 'mobile repairs');
        $this->assertSame(array_slice(array_reverse($ids), 0, 20), $page(1));
    }
}

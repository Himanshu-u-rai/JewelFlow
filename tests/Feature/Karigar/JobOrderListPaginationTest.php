<?php

namespace Tests\Feature\Karigar;

use App\Models\JobOrder;
use App\Models\Karigar;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** Job orders issued in the same second must each show on exactly one page. */
class JobOrderListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    public function test_job_orders_with_the_same_created_at_each_appear_on_exactly_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();

        $ids = TenantContext::runFor($shop->id, function () use ($shop) {
            $karigar = Karigar::create(['shop_id' => $shop->id, 'name' => 'Same second workshop']);

            return collect(range(1, 60))->map(fn ($n) => JobOrder::create([
                'shop_id' => $shop->id, 'karigar_id' => $karigar->id,
                'job_order_number' => 'PAGE-JO-' . $n, 'challan_number' => 'PAGE-DC-' . $n,
                'metal_type' => 'gold', 'purity' => 22, 'issue_date' => '2026-11-01',
            ])->id)->all();
        });
        $this->tieAndScramble('job_orders', $ids, ['created_at' => '2026-11-01 10:00:00']);

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/job-orders?page=' . $p)->assertOk()->viewData('orders'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 25, 'job orders');
        $this->assertSame(array_slice(array_reverse($ids), 0, 25), $page(1));
    }
}

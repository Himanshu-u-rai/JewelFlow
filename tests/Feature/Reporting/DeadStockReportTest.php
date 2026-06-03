<?php

namespace Tests\Feature\Reporting;

use App\Reporting\InventoryService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Dead stock / inventory aging at cost (Phase 2 M4, #12). Locks the bucket math:
 * in_stock items aged by created_at, valued at cost_price; buckets sum to total.
 */
class DeadStockReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function stockItem(int $shopId, float $cost, int $ageDays, string $status = 'in_stock'): void
    {
        $item = $this->createItem($shopId);
        $dt = Carbon::now()->subDays($ageDays)->setTime(10, 0);
        DB::table('items')->where('id', $item->id)->update([
            'cost_price' => $cost, 'status' => $status, 'created_at' => $dt,
        ]);
    }

    public function test_dead_stock_buckets_and_total(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->stockItem($shop->id, 1000, 30);   // fresh (0-90)
        $this->stockItem($shop->id, 2000, 120);  // aging (91-180)
        $this->stockItem($shop->id, 3000, 200);  // stale (181-365)
        $this->stockItem($shop->id, 4000, 500);  // dead (365+)
        $this->stockItem($shop->id, 9000, 400, 'sold'); // not in_stock → excluded

        $data = TenantContext::runFor($shop->id, fn () =>
            app(InventoryService::class)->deadStock($shop->id)
        );

        $this->assertSame(4, $data->totalCount, 'sold item excluded');
        $this->assertEqualsWithDelta(1000.0, $data->freshValue, 0.01);
        $this->assertEqualsWithDelta(2000.0, $data->agingValue, 0.01);
        $this->assertEqualsWithDelta(3000.0, $data->staleValue, 0.01);
        $this->assertEqualsWithDelta(4000.0, $data->deadValue, 0.01);
        $this->assertEqualsWithDelta(10000.0, $data->totalValue, 0.01);

        // Core invariant — buckets sum to the total.
        $this->assertEqualsWithDelta(
            $data->totalValue,
            $data->freshValue + $data->agingValue + $data->staleValue + $data->deadValue,
            0.01
        );

        // Oldest list = items aged > 180 days (stale + dead), oldest first.
        $this->assertSame(2, $data->oldest->count());
        $this->assertGreaterThanOrEqual($data->oldest->last()->age_days, $data->oldest->first()->age_days);
    }
}

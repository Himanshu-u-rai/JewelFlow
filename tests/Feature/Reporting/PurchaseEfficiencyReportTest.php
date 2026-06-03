<?php

namespace Tests\Feature\Reporting;

use App\Reporting\InventoryService;
use App\Reporting\ReportPeriod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Purchase efficiency (Phase 2 M4, #14). Locks the purity-normalized
 * paid-vs-market premium, and that lines with no recorded market rate are
 * excluded from the premium (counted separately).
 */
class PurchaseEfficiencyReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const YEAR = 2026;
    private const MONTH = 4;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function dailyRate(int $shopId, string $date, float $gold24k): void
    {
        DB::table('shop_daily_metal_rates')->insert([
            'shop_id' => $shopId, 'business_date' => $date, 'timezone' => 'Asia/Kolkata',
            'gold_24k_rate_per_gram' => $gold24k, 'silver_999_rate_per_gram' => 80,
            'entered_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function purchaseLine(int $shopId, string $date, string $metal, float $purity, float $gross, float $rate): void
    {
        $pid = (int) DB::table('stock_purchases')->insertGetId([
            'shop_id' => $shopId, 'purchase_number' => 'PO-' . fake()->unique()->numerify('######'),
            'purchase_date' => $date, 'status' => 'stocked', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_purchase_items')->insert([
            'shop_id' => $shopId, 'stock_purchase_id' => $pid, 'line_type' => 'ornament',
            'metal_type' => $metal, 'purity' => $purity, 'gross_weight' => $gross,
            'net_metal_weight' => $gross, 'purchase_rate_per_gram' => $rate,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_premium_vs_market_excludes_no_rate_lines(): void
    {
        [, $shop] = $this->createRetailerTenant();

        // 24k market = 7000/g; a 22k gold line paid 6500/g, 10g.
        $this->dailyRate($shop->id, '2026-04-15', 7000);
        $this->purchaseLine($shop->id, '2026-04-15', 'gold', 22, 10, 6500);
        // A line on a date with NO recorded market rate → excluded from premium.
        $this->purchaseLine($shop->id, '2026-04-20', 'gold', 22, 5, 6600);

        $data = TenantContext::runFor($shop->id, fn () =>
            app(InventoryService::class)->purchaseEfficiency($shop->id, ReportPeriod::month(self::YEAR, self::MONTH))
        );

        $marketAt22k = 7000 * (22 / 24);           // 6416.6667
        $expectedPremium = round((6500 - $marketAt22k) * 10, 2); // ~833.33

        $this->assertSame(2, $data->lineCount);
        $this->assertSame(1, $data->linesNoMarket);
        $this->assertEqualsWithDelta($expectedPremium, $data->totalPremium, 0.05,
            'premium compares only the known-market line, not the no-rate line');
        // Per-metal premium sums to the total (invariant).
        $this->assertEqualsWithDelta($data->totalPremium, (float) $data->rows->sum('premium'), 0.05);
        // Total purchase cost includes BOTH lines; market cost only the known one.
        $this->assertEqualsWithDelta(6500 * 10 + 6600 * 5, $data->totalPurchaseCost, 0.05);
        $this->assertEqualsWithDelta(round($marketAt22k * 10, 2), $data->totalMarketCost, 0.05);
    }
}

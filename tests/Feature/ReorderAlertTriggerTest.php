<?php

namespace Tests\Feature;

use App\Models\ReorderRule;
use App\Services\ReorderAlertService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Pilot-readiness: prove the Reorder / low-stock alert is a real, data-backed
 * trigger — not a decorative count. A rule whose matching in-stock count is
 * below its threshold must surface an alert; restocking to/above the threshold
 * must clear it; and the alert must be shop-scoped (no cross-shop leak).
 */
class ReorderAlertTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function rule(int $shopId, string $category, int $threshold): void
    {
        $r = new ReorderRule();
        $r->forceFill([
            'shop_id'             => $shopId,
            'category'            => $category,
            'min_stock_threshold' => $threshold,
        ])->save();
    }

    private function alerts(int $shopId)
    {
        $svc = app(ReorderAlertService::class);
        $svc->clearCache($shopId); // count is cached 5 min; force a fresh read
        return $svc->getAlerts($shopId);
    }

    public function test_low_stock_below_threshold_triggers_alert(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $this->rule($shop->id, 'Ring', 5);

        // Only 2 in-stock Rings — below the threshold of 5.
        TenantContext::runFor($shop->id, function () use ($shop, $lot) {
            $this->createItem($shop->id, $lot->id, ['category' => 'Ring', 'status' => 'in_stock']);
            $this->createItem($shop->id, $lot->id, ['category' => 'Ring', 'status' => 'in_stock']);
        });

        $alerts = $this->alerts($shop->id);
        $this->assertCount(1, $alerts, 'a below-threshold rule must surface exactly one alert');
        $this->assertTrue((bool) $alerts->first()['below_threshold']);
        $this->assertSame(2, (int) $alerts->first()['current_stock']);
        $this->assertSame(5, (int) $alerts->first()['threshold']);
        $this->assertSame(1, app(ReorderAlertService::class)->alertCount($shop->id));
    }

    public function test_restocking_to_threshold_clears_alert(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $this->rule($shop->id, 'Ring', 5);

        TenantContext::runFor($shop->id, function () use ($shop, $lot) {
            for ($i = 0; $i < 2; $i++) {
                $this->createItem($shop->id, $lot->id, ['category' => 'Ring', 'status' => 'in_stock']);
            }
        });
        $this->assertCount(1, $this->alerts($shop->id), 'alert present while short');

        // Restock up to the threshold (total 5 in-stock Rings).
        TenantContext::runFor($shop->id, function () use ($shop, $lot) {
            for ($i = 0; $i < 3; $i++) {
                $this->createItem($shop->id, $lot->id, ['category' => 'Ring', 'status' => 'in_stock']);
            }
        });

        $this->assertCount(0, $this->alerts($shop->id), 'alert clears once stock reaches the threshold');
    }

    public function test_alert_is_shop_scoped(): void
    {
        // Shop A is short on Rings; Shop B has the same rule but is well-stocked.
        [, $shopA] = $this->createRetailerTenant();
        $lotA = $this->createMetalLot($shopA->id);
        $this->rule($shopA->id, 'Ring', 5);
        TenantContext::runFor($shopA->id, fn () => $this->createItem($shopA->id, $lotA->id, ['category' => 'Ring', 'status' => 'in_stock']));

        [, $shopB] = $this->createRetailerTenant();
        $lotB = $this->createMetalLot($shopB->id);
        $this->rule($shopB->id, 'Ring', 5);
        TenantContext::runFor($shopB->id, function () use ($shopB, $lotB) {
            for ($i = 0; $i < 6; $i++) {
                $this->createItem($shopB->id, $lotB->id, ['category' => 'Ring', 'status' => 'in_stock']);
            }
        });

        $this->assertCount(1, $this->alerts($shopA->id), 'shop A sees its own shortage');
        $this->assertCount(0, $this->alerts($shopB->id), 'shop B is stocked — no alert, no leak from A');
    }
}

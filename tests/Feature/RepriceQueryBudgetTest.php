<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Saving today's rates reprices every in-stock item inside the request (the queue runs sync in
 * production). Each item used to repeat the same three lookups (today's rate, its purity profile, its
 * resolved rate): 300 items = 1,262 queries, ~3 s for the owner. A whole-shop reprice now does those
 * lookups once per run, so it costs one UPDATE per item plus a small constant.
 */
class RepriceQueryBudgetTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_repricing_a_shop_costs_one_update_per_item_plus_a_constant(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $owner);
        TenantContext::set($shop->id);
        foreach (range(1, 40) as $i) {
            $this->createItem($shop->id, null, ['purity' => $i % 2 ? 22.00 : 18.00]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $updated = app(ShopPricingService::class)->repriceInStockItems($shop->id);
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $queries = count($log);
        $repeated = collect($log)->countBy(fn ($q) => substr(preg_replace('/\s+/', ' ', $q['query']), 0, 80))
            ->filter(fn ($n) => $n > 2)->map(fn ($n, $q) => "$n x $q")->implode('; ');

        $this->assertSame(40, $updated);
        // one UPDATE per item; the rest (~23 today) is per-run setup: schema checks, the item chunk, shared lookups
        $this->assertLessThanOrEqual(40 + 30, $queries, "repricing 40 items took $queries queries; repeated: $repeated");
    }

    public function test_repriced_values_are_unchanged_by_the_shared_lookups(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $owner, ['gold_24k_rate_per_gram' => 7200]);
        TenantContext::set($shop->id);
        $a = $this->createItem($shop->id, null, ['purity' => 22.00, 'cost_price' => 1, 'selling_price' => 1]);
        $b = $this->createItem($shop->id, null, ['purity' => 18.00, 'cost_price' => 1, 'selling_price' => 1]);
        $service = app(ShopPricingService::class);

        $service->repriceInStockItems($shop->id);

        foreach ([$a, $b] as $item) {
            $fresh = Item::withoutTenant()->find($item->id);
            $expected = $service->computeRetailerCostPayload($shop, [
                'metal_type' => 'gold', 'purity' => $item->purity, 'gross_weight' => $item->gross_weight,
                'stone_weight' => $item->stone_weight, 'making_charges' => $item->making_charges,
                'stone_charges' => $item->stone_charges, 'hallmark_charges' => 0, 'rhodium_charges' => 0, 'other_charges' => 0,
            ]);
            $this->assertEquals($expected['selling_price'], (float) $fresh->selling_price);
            $this->assertEquals($expected['cost_price'], (float) $fresh->cost_price);
        }
    }
}

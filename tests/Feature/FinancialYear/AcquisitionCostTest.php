<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Item;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopPricingService;
use App\Services\StockHistoryBackfill;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Year-end stock needs the real purchase cost (owner, 2026-11-16). items.cost_price is the CURRENT replacement cost
 * and is overwritten on every daily-rate save; acquisition_cost is set once at intake and never changes.
 */
class AcquisitionCostTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$this->owner, $this->shop] = $this->createRetailerTenant();
    }

    private function item(array $attrs = []): Item
    {
        return $this->createItem($this->shop->id, null, $attrs);
    }

    private function row(int $id): object
    {
        return DB::table('items')->where('id', $id)->first();
    }

    public function test_item_created_with_acquisition_cost_keeps_it_and_source(): void
    {
        $item = $this->item(['acquisition_cost' => 41234.50, 'acquisition_cost_source' => 'entered']);

        $this->assertSame('41234.50', $this->row($item->id)->acquisition_cost);
        $this->assertSame('entered', $this->row($item->id)->acquisition_cost_source);
    }

    public function test_item_created_without_acquisition_cost_is_unknown_and_null_never_invented(): void
    {
        $item = $this->item(['cost_price' => 50000]);

        $this->assertNull($this->row($item->id)->acquisition_cost);
        $this->assertSame('unknown', $this->row($item->id)->acquisition_cost_source);
    }

    public function test_invalid_source_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->item(['acquisition_cost' => 10, 'acquisition_cost_source' => 'guess']);
    }

    public function test_direct_sql_update_of_a_set_acquisition_cost_is_refused(): void
    {
        $item = $this->item(['acquisition_cost' => 1000, 'acquisition_cost_source' => 'purchase']);

        try {
            DB::transaction(fn () => DB::table('items')->where('id', $item->id)->update(['acquisition_cost' => 2000]));
            $this->fail('UPDATE of acquisition_cost must be refused');
        } catch (QueryException $e) {
            $this->assertStringContainsString('acquisition_cost', $e->getMessage());
        }
        $this->assertSame('1000.00', $this->row($item->id)->acquisition_cost);
    }

    public function test_clearing_or_relabelling_a_set_acquisition_cost_is_refused(): void
    {
        $item = $this->item(['acquisition_cost' => 1000, 'acquisition_cost_source' => 'purchase']);

        foreach ([['acquisition_cost' => null], ['acquisition_cost_source' => 'entered']] as $change) {
            $refused = false;
            try {
                DB::transaction(fn () => DB::table('items')->where('id', $item->id)->update($change));
            } catch (QueryException) {
                $refused = true;
            }
            $this->assertTrue($refused, 'change must be refused: ' . json_encode($change));
        }
    }

    public function test_other_columns_stay_editable_when_cost_is_set(): void
    {
        $item = $this->item(['acquisition_cost' => 1000, 'acquisition_cost_source' => 'purchase']);
        DB::table('items')->where('id', $item->id)->update(['selling_price' => 777, 'cost_price' => 1234]);

        $this->assertSame('1000.00', $this->row($item->id)->acquisition_cost);
        $this->assertSame('1234.00', $this->row($item->id)->cost_price);
    }

    public function test_a_null_acquisition_cost_can_be_set_once_but_not_with_source_unknown(): void
    {
        $item = $this->item();

        $refused = false;
        try {
            DB::transaction(fn () => DB::table('items')->where('id', $item->id)->update(['acquisition_cost' => 900]));
        } catch (QueryException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'cost with source unknown must be refused');

        DB::table('items')->where('id', $item->id)->update(['acquisition_cost' => 900, 'acquisition_cost_source' => 'entered']);
        $this->assertSame('900.00', $this->row($item->id)->acquisition_cost);
    }

    public function test_repricing_leaves_acquisition_cost_unchanged_but_moves_cost_price(): void
    {
        $this->seedRetailerPricing($this->shop, $this->owner, ['gold_24k_rate_per_gram' => 7000]);
        $item = $this->item([
            'acquisition_cost' => 40000, 'acquisition_cost_source' => 'purchase',
            'cost_price' => 40000, 'metal_type' => 'gold', 'purity' => 22, 'status' => 'in_stock',
        ]);

        $this->seedRetailerPricing($this->shop, $this->owner, ['gold_24k_rate_per_gram' => 9000]);
        $updated = TenantContext::runFor($this->shop->id, fn () => app(ShopPricingService::class)->repriceInStockItems($this->shop->id));

        $row = $this->row($item->id);
        $this->assertGreaterThan(0, $updated, 'reprice must have run for this test to mean anything');
        $this->assertNotSame('40000.00', $row->cost_price, "cost_price must follow today's rate");
        $this->assertSame('40000.00', $row->acquisition_cost);
        $this->assertSame('purchase', $row->acquisition_cost_source);
    }

    public function test_item_model_exposes_the_allowed_sources(): void
    {
        $this->assertSame(['purchase', 'entered', 'opening', 'import', 'unknown'], Item::ACQUISITION_SOURCES);
    }

    public function test_backfill_takes_cost_from_purchase_lines_and_leaves_the_rest_null_unknown(): void
    {
        $fromPurchase = $this->item(['net_metal_weight' => 10, 'source' => 'purchased', 'cost_price' => 99999]);
        $other = $this->item(['cost_price' => 123456]);

        $purchaseId = DB::table('stock_purchases')->insertGetId([
            'shop_id' => $this->shop->id, 'purchase_number' => 'SP-T-1', 'purchase_date' => '2026-01-10',
            'status' => 'confirmed', 'entered_by_user_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('items')->where('id', $fromPurchase->id)->update(['stock_purchase_id' => $purchaseId]);
        DB::table('stock_purchase_items')->insert([
            'stock_purchase_id' => $purchaseId, 'shop_id' => $this->shop->id, 'item_id' => $fromPurchase->id,
            'line_type' => 'ornament', 'net_metal_weight' => 10.5, 'purchase_rate_per_gram' => 6000.1234,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        StockHistoryBackfill::acquisitionCosts();
        StockHistoryBackfill::acquisitionCosts(); // idempotent

        $a = $this->row($fromPurchase->id);
        $b = $this->row($other->id);
        $this->assertSame('63001.30', $a->acquisition_cost); // 10.5 x 6000.1234, 2 dp
        $this->assertSame('purchase', $a->acquisition_cost_source);
        $this->assertNull($b->acquisition_cost, 'NULL is the truthful value; cost_price must not be copied');
        $this->assertSame('unknown', $b->acquisition_cost_source);
    }
}

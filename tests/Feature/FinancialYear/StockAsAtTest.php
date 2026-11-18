<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Item;
use App\Models\Shop;
use App\Services\StockAsAt;
use App\Services\StockHistoryBackfill;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** Stock "as at 31 March" must be rebuildable from item_movements long after the items moved on. */
class StockAsAtTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [, $this->shop] = $this->createRetailerTenant();
    }

    private function at(string $ist): void
    {
        $this->travelTo(CarbonImmutable::parse($ist, 'Asia/Kolkata'));
    }

    private function setStatus(int $id, string $status): void
    {
        Item::withoutTenant()->whereKey($id)->first()->update(['status' => $status]);
    }

    private function ids(string $date, ?int $shopId = null): array
    {
        return StockAsAt::itemsInStock($shopId ?? $this->shop->id, $date)->pluck('item_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    public function test_sell_return_resell_rebuilds_correctly_at_each_date(): void
    {
        $this->at('2026-01-05 10:00:00');
        $item = $this->createItem($this->shop->id);
        $this->at('2026-02-10 15:00:00');
        $this->setStatus($item->id, 'sold');
        $this->at('2026-03-05 12:00:00');
        $this->setStatus($item->id, 'in_stock');
        $this->at('2026-04-20 12:00:00');
        $this->setStatus($item->id, 'sold');

        $this->assertSame([], $this->ids('2026-01-04'), 'before intake');
        $this->assertSame([$item->id], $this->ids('2026-01-05'), 'end of intake day');
        $this->assertSame([$item->id], $this->ids('2026-02-09'));
        $this->assertSame([], $this->ids('2026-02-10'), 'sold that day');
        $this->assertSame([], $this->ids('2026-03-04'));
        $this->assertSame([$item->id], $this->ids('2026-03-31'), 'returned, in stock at year end');
        $this->assertSame([], $this->ids('2026-04-20'), 'resold');
    }

    public function test_item_created_after_the_date_is_excluded_and_sold_after_the_date_is_included(): void
    {
        $this->at('2026-03-01 10:00:00');
        $soldLater = $this->createItem($this->shop->id);
        $this->at('2026-04-02 10:00:00');
        $createdLater = $this->createItem($this->shop->id);
        $this->at('2026-04-10 10:00:00');
        $this->setStatus($soldLater->id, 'sold');

        $this->assertSame([$soldLater->id], $this->ids('2026-03-31'));
        $this->assertSame([$createdLater->id], $this->ids('2026-04-30'));
    }

    public function test_two_shops_are_isolated(): void
    {
        $this->at('2026-03-01 10:00:00');
        $mine = $this->createItem($this->shop->id);
        [, $other] = $this->createRetailerTenant();
        $theirs = $this->createItem($other->id);

        $this->assertSame([$mine->id], $this->ids('2026-03-31'));
        $this->assertSame([$theirs->id], $this->ids('2026-03-31', $other->id));
    }

    public function test_a_deleted_item_drops_out_after_deletion_but_stays_before(): void
    {
        $this->at('2026-03-01 10:00:00');
        $item = $this->createItem($this->shop->id);
        $this->at('2026-05-01 10:00:00');
        DB::table('items')->where('id', $item->id)->delete();

        $this->travelBack(); // the raw DELETE is stamped by the database clock, not the faked one
        $this->assertSame([$item->id], $this->ids('2026-03-31'));
        $this->assertSame([], $this->ids(CarbonImmutable::now('Asia/Kolkata')->toDateString()));
    }

    public function test_rows_carry_the_historical_snapshot_and_acquisition_cost(): void
    {
        $this->at('2026-03-01 10:00:00');
        $item = $this->createItem($this->shop->id, null, [
            'gross_weight' => 10, 'net_metal_weight' => 9.5, 'purity' => 22, 'metal_type' => 'gold',
            'acquisition_cost' => 55000, 'acquisition_cost_source' => 'purchase', 'cost_price' => 60000,
        ]);
        $this->at('2026-03-10 10:00:00');
        DB::table('items')->where('id', $item->id)->update(['cost_price' => 70000]); // reprice-like change

        $row = StockAsAt::itemsInStock($this->shop->id, '2026-03-31')->first();
        $this->assertSame('55000.00', $row->acquisition_cost);
        $this->assertSame('gold', $row->metal_type);
        $this->assertSame('9.500000', $row->net_metal_weight);
    }

    public function test_item_with_karigar_still_counts_as_owned_stock_but_melted_does_not(): void
    {
        $this->at('2026-03-01 10:00:00');
        $a = $this->createItem($this->shop->id);
        $b = $this->createItem($this->shop->id);
        $this->at('2026-03-02 10:00:00');
        $this->setStatus($a->id, 'with_karigar');
        $this->setStatus($b->id, 'melted');

        $this->assertSame([$a->id], $this->ids('2026-03-31'));
    }

    public function test_backfill_builds_intake_and_status_movements_for_pre_existing_items(): void
    {
        // Pre-existing data = items with no movements. Triggers always write them, so run the backfill SQL
        // against a trigger-less copy of the ledger table (same columns) built from the live items.
        $this->at('2026-01-05 10:00:00');
        $inStock = $this->createItem($this->shop->id, null, ['acquisition_cost' => 5000, 'acquisition_cost_source' => 'purchase']);
        $sold = $this->createItem($this->shop->id);
        DB::statement("UPDATE items SET status = 'sold', updated_at = '2026-02-02 09:00:00' WHERE id = ?", [$sold->id]);
        $melted = $this->createItem($this->shop->id);
        DB::statement("UPDATE items SET status = 'melted', updated_at = '2026-03-03 09:00:00' WHERE id = ?", [$melted->id]);

        DB::statement('CREATE TEMP TABLE backfill_probe (LIKE item_movements INCLUDING DEFAULTS INCLUDING GENERATED)');
        StockHistoryBackfill::movements('backfill_probe');
        StockHistoryBackfill::movements('backfill_probe'); // idempotent

        $rows = DB::table('backfill_probe')->orderBy('item_id')->orderBy('id')->get()->groupBy('item_id');
        $this->assertCount(1, $rows[$inStock->id]);
        $this->assertSame('intake', $rows[$inStock->id][0]->movement_type);
        $this->assertSame('5000.00', $rows[$inStock->id][0]->acquisition_cost);
        $this->assertTrue((bool) $rows[$inStock->id][0]->is_backfilled);
        $this->assertSame(['intake', 'sold'], $rows[$sold->id]->pluck('movement_type')->all());
        $this->assertSame('2026-02-02 09:00:00', substr($rows[$sold->id][1]->occurred_at, 0, 19));
        $this->assertSame(['intake', 'melted'], $rows[$melted->id]->pluck('movement_type')->all());
        $this->assertSame('2026-01-05 10:00:00', substr($rows[$sold->id][0]->occurred_at, 0, 19));
    }
}

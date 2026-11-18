<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Item;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * item_movements: an append-only history of every item's status, written by DB triggers so no code path
 * (Eloquent, query builder, raw SQL) can change stock without leaving a row.
 */
class ItemMovementLedgerTest extends TestCase
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

    private function movements(int $itemId)
    {
        return DB::table('item_movements')->where('item_id', $itemId)->orderBy('id')->get();
    }

    public function test_creating_an_item_writes_an_intake_movement_with_snapshot(): void
    {
        $this->at('2026-02-10 11:00:00');
        $item = $this->createItem($this->shop->id, null, [
            'gross_weight' => 12.5, 'net_metal_weight' => 12.0, 'purity' => 22, 'metal_type' => 'gold',
            'acquisition_cost' => 80000, 'acquisition_cost_source' => 'purchase',
        ]);

        $m = $this->movements($item->id);
        $this->assertCount(1, $m);
        $this->assertSame('intake', $m[0]->movement_type);
        $this->assertNull($m[0]->from_status);
        $this->assertSame('in_stock', $m[0]->to_status);
        $this->assertSame($this->shop->id, (int) $m[0]->shop_id);
        $this->assertSame('12.500000', $m[0]->gross_weight);
        $this->assertSame('gold', $m[0]->metal_type);
        $this->assertSame('80000.00', $m[0]->acquisition_cost);
        $this->assertSame('2026-02-10 11:00:00', substr($m[0]->occurred_at, 0, 19));
        $this->assertFalse((bool) $m[0]->is_backfilled);
    }

    public function test_intake_uses_a_backdated_created_at(): void
    {
        $item = $this->createItem($this->shop->id, null, ['created_at' => '2025-04-01 09:00:00', 'updated_at' => '2025-04-01 09:00:00']);

        $this->assertSame('2025-04-01 09:00:00', substr($this->movements($item->id)[0]->occurred_at, 0, 19));
    }

    public function test_status_changes_write_typed_movements_via_eloquent(): void
    {
        $item = $this->createItem($this->shop->id);
        foreach (['sold' => 'sold', 'in_stock' => 'returned_to_stock', 'melted' => 'melted', 'transferred' => 'transferred', 'reversed' => 'reversed'] as $to => $type) {
            $this->at('2026-03-01 10:00:00');
            Item::withoutTenant()->whereKey($item->id)->first()->update(['status' => $to]);
        }

        $types = $this->movements($item->id)->pluck('movement_type')->all();
        $this->assertSame(['intake', 'sold', 'returned_to_stock', 'melted', 'transferred', 'reversed'], $types);
        $m = $this->movements($item->id);
        $this->assertSame('in_stock', $m[1]->from_status);
        $this->assertSame('sold', $m[1]->to_status);
    }

    public function test_a_raw_sql_status_change_still_writes_a_movement(): void
    {
        $item = $this->createItem($this->shop->id);
        DB::statement('UPDATE items SET status = ? WHERE id = ?', ['sold', $item->id]);

        $m = $this->movements($item->id);
        $this->assertCount(2, $m);
        $this->assertSame('sold', $m[1]->movement_type);
    }

    public function test_updating_other_columns_or_same_status_writes_nothing(): void
    {
        $item = $this->createItem($this->shop->id);
        DB::table('items')->where('id', $item->id)->update(['selling_price' => 1, 'status' => 'in_stock']);

        $this->assertCount(1, $this->movements($item->id));
    }

    public function test_hard_deleting_an_item_writes_a_deleted_movement_and_history_survives(): void
    {
        $item = $this->createItem($this->shop->id);
        DB::table('items')->where('id', $item->id)->delete();

        $m = $this->movements($item->id);
        $this->assertSame(['intake', 'deleted'], $m->pluck('movement_type')->all());
        $this->assertSame('in_stock', $m[1]->from_status);
        $this->assertNull($m[1]->to_status);
    }

    public function test_item_movements_cannot_be_updated(): void
    {
        $item = $this->createItem($this->shop->id);
        $refused = false;
        try {
            DB::table('item_movements')->where('item_id', $item->id)->update(['to_status' => 'sold']);
        } catch (QueryException $e) {
            $refused = true;
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertTrue($refused);
    }

    public function test_item_movements_cannot_be_deleted(): void
    {
        $item = $this->createItem($this->shop->id);
        $refused = false;
        try {
            DB::table('item_movements')->where('item_id', $item->id)->delete();
        } catch (QueryException) {
            $refused = true;
        }
        $this->assertTrue($refused);
    }

    public function test_the_four_constitutional_triggers_exist(): void
    {
        $names = DB::table('information_schema.triggers')->pluck('trigger_name')->unique()->all();
        foreach (['items_acquisition_cost_guard_trigger', 'items_intake_movement_trigger', 'items_status_movement_trigger',
                  'items_delete_movement_trigger', 'item_movements_append_only_trigger'] as $t) {
            $this->assertContains($t, $names);
        }
    }
}

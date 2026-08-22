<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The retailer inventory index embeds the stock-aging analytics tab, which reads
 * the same RetailerReportService payload as /report/stock-aging. Every existing
 * test only ever assertRedirect()s to this route, so the page itself was never
 * rendered by CI — the same blind spot that let the stock-aging 500 reach
 * production. One real GET, with and without stock, closes it.
 */
class InventoryItemsIndexRenderTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_retailer_items_index_renders_with_no_stock(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)
            ->get(route('inventory.items.index'))
            ->assertOk();
    }

    /**
     * One item in each of the five aging buckets, so the tab renders a fully
     * populated bucket map and the slow-moving table rather than just zeros.
     */
    public function test_retailer_items_index_renders_with_aged_stock(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        foreach ([2, 45, 75, 120, 200] as $daysOld) {
            $item = $this->createItem($shop->id);
            // created_at is the aging clock, and the helper stamps it to now().
            DB::table('items')->where('id', $item->id)
                ->update(['created_at' => now()->subDays($daysOld)]);
        }

        $this->actingAs($owner)
            ->get(route('inventory.items.index'))
            ->assertOk();
    }
}

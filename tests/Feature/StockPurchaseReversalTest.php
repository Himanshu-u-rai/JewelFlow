<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\StockPurchase;
use App\Models\StockPurchaseItem;
use App\Services\StockPurchaseService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M7 (audit A4): confirm/addToInventory were one-way — a wrongly
 * confirmed or stocked purchase could not be undone (it orphaned Items + lots).
 * reversePurchase() returns it to an editable DRAFT, but only when nothing
 * downstream has consumed it: stocked items must still be 'in_stock' and no
 * bullion line may have been vaulted; otherwise it blocks with a clear message.
 */
class StockPurchaseReversalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makePurchase(int $shopId, string $status): StockPurchase
    {
        $p = new StockPurchase();
        $p->forceFill([
            'shop_id' => $shopId,
            'purchase_number' => 'PO-' . uniqid(),
            'purchase_date' => now()->toDateString(),
            'status' => $status,
        ])->save();
        return $p;
    }

    private function addOrnamentLine(StockPurchase $p, ?int $itemId = null): StockPurchaseItem
    {
        $line = new StockPurchaseItem();
        $line->forceFill([
            'stock_purchase_id' => $p->id,
            'shop_id' => $p->shop_id,
            'line_type' => 'ornament',
            'design' => 'Ring',
            'metal_type' => 'gold',
            'purity' => 22,
            'gross_weight' => 10,
            'net_metal_weight' => 9.5,
            'item_id' => $itemId,
        ])->save();
        return $line;
    }

    public function test_route_and_gate_exist(): void
    {
        $this->assertTrue(Route::has('inventory.purchases.reverse'));
        $route = Route::getRoutes()->getByName('inventory.purchases.reverse');
        $this->assertContains('can:inventory.edit', $route->gatherMiddleware());
    }

    public function test_confirmed_reverts_to_draft(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $p = $this->makePurchase($shop->id, 'confirmed');
        $this->addOrnamentLine($p);

        TenantContext::runFor($shop->id, fn () =>
            app(StockPurchaseService::class)->reversePurchase($p, $owner->id)
        );

        $this->assertSame('draft', StockPurchase::withoutGlobalScopes()->find($p->id)->status);
    }

    public function test_stocked_reversal_removes_in_stock_items(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $p = $this->makePurchase($shop->id, 'stocked');
        $item = $this->createItem($shop->id, null, ['status' => 'in_stock', 'stock_purchase_id' => $p->id]);
        $this->addOrnamentLine($p, $item->id);

        TenantContext::runFor($shop->id, fn () =>
            app(StockPurchaseService::class)->reversePurchase($p, $owner->id)
        );

        $this->assertNull(Item::withoutGlobalScopes()->find($item->id), 'in-stock item removed on reversal');
        $fresh = StockPurchase::withoutGlobalScopes()->find($p->id);
        $this->assertSame('draft', $fresh->status);
        $this->assertNull(StockPurchaseItem::withoutGlobalScopes()->where('stock_purchase_id', $p->id)->first()->item_id);
        $this->assertDatabaseHas('audit_logs', ['shop_id' => $shop->id, 'action' => 'purchase_reversed', 'model_id' => $p->id]);
    }

    public function test_blocks_when_a_created_item_is_no_longer_in_stock(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $p = $this->makePurchase($shop->id, 'stocked');
        $item = $this->createItem($shop->id, null, ['status' => 'sold', 'stock_purchase_id' => $p->id]);
        $this->addOrnamentLine($p, $item->id);

        $threw = false;
        try {
            TenantContext::runFor($shop->id, fn () =>
                app(StockPurchaseService::class)->reversePurchase($p, $owner->id)
            );
        } catch (\LogicException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'reversal must be blocked when an item left stock');
        $this->assertSame('stocked', StockPurchase::withoutGlobalScopes()->find($p->id)->status);
        $this->assertNotNull(Item::withoutGlobalScopes()->find($item->id), 'sold item must be untouched');
    }

    public function test_blocks_when_bullion_line_already_vaulted(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $p = $this->makePurchase($shop->id, 'stocked');
        $lot = $this->createMetalLot($shop->id, 50.0);
        $line = new StockPurchaseItem();
        $line->forceFill([
            'stock_purchase_id' => $p->id, 'shop_id' => $shop->id,
            'line_type' => 'bullion_reserve', 'metal_type' => 'gold', 'purity' => 24,
            'gross_weight' => 50, 'net_metal_weight' => 50, 'metal_lot_id' => $lot->id,
        ])->save();

        $threw = false;
        try {
            TenantContext::runFor($shop->id, fn () =>
                app(StockPurchaseService::class)->reversePurchase($p, $owner->id)
            );
        } catch (\LogicException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'reversal must be blocked when bullion already vaulted');
    }
}

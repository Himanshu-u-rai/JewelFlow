<?php

namespace Tests\Feature\Returns;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Bug B regression: InvoiceItem's Eloquent updating guard must mirror the DB
 * trigger allow-list (returned_at, return_line_item_id, allocated_*), not
 * blanket-block all updates. The lifecycle columns must be writable so the
 * return/exchange "mark as returned" save no longer crashes — while every
 * financial/identity column stays immutable and delete stays blocked.
 */
class InvoiceItemReturnGuardTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** Helper: sell an item and return the finalized invoice's line item. */
    private function soldLineItem(): array
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id, 'customer_id' => $customer->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
        ]);
        $total = (float) $preview->json('total');

        $sell = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_id' => $item->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => $total]],
        ])->assertOk();

        TenantContext::set($shop->id);
        $line = InvoiceItem::where('invoice_id', $sell->json('invoice_id'))->firstOrFail();

        return [$user, $shop, $line];
    }

    /** Case 1: setting the return lifecycle columns post-finalize no longer crashes. */
    public function test_returned_at_and_return_line_item_id_are_writable_post_finalize(): void
    {
        [, $shop, $line] = $this->soldLineItem();
        TenantContext::set($shop->id);

        $line->forceFill([
            'returned_at'         => now(),
            'return_line_item_id' => null,
        ])->save();

        $this->assertNotNull($line->fresh()->returned_at);
    }

    /** Case 4: a protected financial column must still throw. */
    public function test_financial_columns_remain_immutable(): void
    {
        [, $shop, $line] = $this->soldLineItem();
        TenantContext::set($shop->id);

        $this->expectException(\LogicException::class);
        $line->forceFill(['line_total' => 999999])->save();
    }

    /** Case 4b: item identity / weight must still throw. */
    public function test_weight_and_identity_columns_remain_immutable(): void
    {
        [, $shop, $line] = $this->soldLineItem();
        TenantContext::set($shop->id);

        $this->expectException(\LogicException::class);
        $line->forceFill(['weight' => 1.234])->save();
    }

    /** Case 5: deleting an invoice item must still throw. */
    public function test_invoice_item_delete_remains_blocked(): void
    {
        [, $shop, $line] = $this->soldLineItem();
        TenantContext::set($shop->id);

        $this->expectException(\LogicException::class);
        $line->delete();
    }
}

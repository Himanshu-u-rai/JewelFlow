<?php

namespace Tests\Feature\Inventory;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression: the item detail page resolved its invoice via a nonexistent
 * Item::invoice() relation (items carry no invoice_id column), so every sold
 * item rendered "Not linked" instead of the invoice that sold it. The sale
 * linkage lives on invoice_items; Item::latestInvoiceItem() now resolves it.
 */
class ItemShowInvoiceLinkTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    public function test_sold_item_page_links_the_invoice_that_sold_it(): void
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

        $invoice = TenantContext::runFor($shop->id, fn () => Invoice::findOrFail($sell->json('invoice_id')));

        // The model relation resolves the selling invoice through invoice_items.
        $fresh = TenantContext::runFor($shop->id, fn () => Item::findOrFail($item->id));
        $this->assertSame('sold', $fresh->status);
        $line = TenantContext::runFor($shop->id, fn () => $fresh->latestInvoiceItem()->first());
        $this->assertInstanceOf(InvoiceItem::class, $line, 'sold item resolves its invoice line');
        $this->assertSame($invoice->id, (int) $line->invoice_id);

        // And the item page renders the invoice number instead of "Not linked".
        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->get(self::ERP . '/inventory/items/' . $item->id));
        $res->assertOk();
        $res->assertSee($invoice->invoice_number);
        $res->assertDontSee('Not linked');
    }
}

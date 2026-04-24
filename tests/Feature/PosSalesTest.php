<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class PosSalesTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── Manufacturer POS ────────────────────────────────────────────

    public function test_manufacturer_can_sell_item_with_cash_payment(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        // Step 1: Preview to get the correct total
        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id,
            'customer_id' => $customer->id,
            'gold_rate' => 6000,
            'making' => 500,
            'stone' => 200,
            'discount' => 0,
            'round_off' => 0,
        ]);
        $preview->assertOk();
        $total = $preview->json('total');

        // Step 2: Sell with the correct payment amount
        $response = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'gold_rate' => 6000,
            'making' => 500,
            'stone' => 200,
            'discount' => 0,
            'round_off' => 0,
            'payments' => [
                ['mode' => 'cash', 'amount' => $total],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['invoice_id', 'invoice_number']);

        // Verify within tenant context
        TenantContext::set($shop->id);

        // Verify item is now sold
        $this->assertEquals('sold', $item->fresh()->status);

        // Verify invoice was created and finalized
        $invoiceId = $response->json('invoice_id');
        $invoice = Invoice::find($invoiceId);
        $this->assertNotNull($invoice);
        $this->assertEquals(Invoice::STATUS_FINALIZED, $invoice->status);
        $this->assertEquals($customer->id, $invoice->customer_id);
        $this->assertEquals($shop->id, $invoice->shop_id);

        // Verify invoice line item exists
        $this->assertEquals(1, InvoiceItem::where('invoice_id', $invoice->id)->count());

        // Verify cash transaction was created
        $this->assertTrue(
            CashTransaction::where('invoice_id', $invoice->id)
                ->where('type', 'in')
                ->exists()
        );

        // Verify audit log
        $this->assertTrue(
            AuditLog::where('shop_id', $shop->id)
                ->where('action', 'sale')
                ->where('model_id', $invoice->id)
                ->exists()
        );

        TenantContext::clear();
    }

    public function test_manufacturer_cannot_sell_already_sold_item(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id, ['status' => 'sold']);

        $response = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'gold_rate' => 6000,
            'making' => 500,
            'stone' => 200,
            'payments' => [
                ['mode' => 'cash', 'amount' => 50000],
            ],
        ]);

        $response->assertStatus(500);
    }

    public function test_manufacturer_sale_validates_required_fields(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $response = $this->actingAs($user)->postJson('/pos/sell', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['customer_id', 'item_id', 'gold_rate', 'payments']);
    }

    public function test_manufacturer_sale_validates_gold_rate_range(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);
        $lot = $this->createMetalLot($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $response = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'gold_rate' => 500, // Below min of 1000
            'making' => 0,
            'stone' => 0,
            'payments' => [['mode' => 'cash', 'amount' => 50000]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gold_rate']);
    }

    public function test_manufacturer_cannot_sell_item_from_another_shop(): void
    {
        [$user1, $shop1] = $this->createManufacturerTenant();
        [$user2, $shop2] = $this->createManufacturerTenant();

        $customer = $this->createCustomer($shop1->id);
        $lot = $this->createMetalLot($shop2->id);
        $item = $this->createItem($shop2->id, $lot->id);

        $response = $this->actingAs($user1)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'gold_rate' => 6000,
            'making' => 500,
            'stone' => 200,
            'payments' => [['mode' => 'cash', 'amount' => 50000]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['item_id']);
    }

    public function test_manufacturer_sale_with_split_payments(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        // Preview to get total
        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id,
            'customer_id' => $customer->id,
            'gold_rate' => 6000,
            'making' => 500,
            'stone' => 200,
        ]);
        $total = (float) $preview->json('total');

        // Split payment: 30000 cash, rest UPI
        $upiAmount = round($total - 30000, 2);

        $response = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'gold_rate' => 6000,
            'making' => 500,
            'stone' => 200,
            'discount' => 0,
            'round_off' => 0,
            'payments' => [
                ['mode' => 'cash', 'amount' => 30000],
                ['mode' => 'upi', 'amount' => $upiAmount, 'reference' => 'UPI-123'],
            ],
        ]);

        $response->assertOk();
        $invoiceId = $response->json('invoice_id');

        TenantContext::set($shop->id);

        // Verify multiple payment records
        $payments = InvoicePayment::where('invoice_id', $invoiceId)->get();
        $this->assertEquals(2, $payments->count());

        // Verify cash transactions created for cash-equivalent modes
        $cashTxns = CashTransaction::where('invoice_id', $invoiceId)->count();
        $this->assertGreaterThanOrEqual(1, $cashTxns);

        TenantContext::clear();
    }

    // ── Retailer POS ────────────────────────────────────────────────

    public function test_retailer_can_sell_items_with_cash_payment(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $customer = $this->createCustomer($shop->id);
        $item1 = $this->createItem($shop->id, null, [
            'selling_price' => 10000,
            'source' => 'purchase',
        ]);
        $item2 = $this->createItem($shop->id, null, [
            'selling_price' => 15000,
            'source' => 'purchase',
        ]);

        // Preview to get total for item1
        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item1->id,
            'customer_id' => $customer->id,
        ]);
        $preview->assertOk();

        // For retailer multi-item sale, total = sum(selling_price) + GST - discount + roundoff
        // (10000 + 15000) * 1.03 = 25750
        $sellingTotal = 25000;
        $gst = round($sellingTotal * 3 / 100, 2);
        $expectedTotal = $sellingTotal + $gst; // 25750

        $response = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_ids' => [$item1->id, $item2->id],
            'discount' => 0,
            'round_off' => 0,
            'payments' => [
                ['mode' => 'cash', 'amount' => $expectedTotal],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['invoice_id', 'invoice_number']);

        TenantContext::set($shop->id);

        // Both items should be sold
        $this->assertEquals('sold', $item1->fresh()->status);
        $this->assertEquals('sold', $item2->fresh()->status);

        // Invoice should be finalized
        $invoice = Invoice::find($response->json('invoice_id'));
        $this->assertNotNull($invoice);
        $this->assertEquals(Invoice::STATUS_FINALIZED, $invoice->status);
        $this->assertEquals(2, $invoice->items()->count());

        TenantContext::clear();
    }

    public function test_retailer_sale_validates_at_least_one_item(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $customer = $this->createCustomer($shop->id);

        $response = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_ids' => [],
            'payments' => [['mode' => 'cash', 'amount' => 1000]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['item_ids']);
    }

    public function test_retailer_cannot_sell_items_from_another_shop(): void
    {
        [$user1, $shop1] = $this->createRetailerTenant();
        [$user2, $shop2] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop1, $user1);
        $this->seedRetailerPricing($shop2, $user2);

        $customer = $this->createCustomer($shop1->id);
        $item = $this->createItem($shop2->id, null, ['selling_price' => 10000]);

        $response = $this->actingAs($user1)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_ids' => [$item->id],
            'payments' => [['mode' => 'cash', 'amount' => 10300]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['item_ids.0']);
    }

    public function test_retailer_requires_payment_or_scheme_redemption(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 10000]);

        $response = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_ids' => [$item->id],
        ]);

        $response->assertStatus(422);
    }

    // ── POS search and barcode ──────────────────────────────────────

    public function test_pos_customer_search_returns_matching_customers(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->createCustomer($shop->id, ['first_name' => 'Rajesh', 'last_name' => 'Kumar']);
        $this->createCustomer($shop->id, ['first_name' => 'Suresh', 'last_name' => 'Patel']);

        $response = $this->actingAs($user)->getJson('/pos/customers/search?search=Rajesh');

        $response->assertOk();
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('Rajesh', $data[0]['first_name']);
    }

    public function test_pos_customer_search_does_not_leak_across_tenants(): void
    {
        [$user1, $shop1] = $this->createManufacturerTenant();
        [$user2, $shop2] = $this->createManufacturerTenant();

        $this->createCustomer($shop2->id, ['first_name' => 'SecretCustomer']);

        $response = $this->actingAs($user1)->getJson('/pos/customers/search?search=SecretCustomer');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_pos_barcode_lookup_returns_item(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->createItem($shop->id, null, ['barcode' => 'TESTBC001']);

        $response = $this->actingAs($user)->getJson('/api/item-by-barcode/TESTBC001');

        $response->assertOk();
        $response->assertJsonFragment(['design' => 'Test Design']);
    }

    public function test_pos_barcode_lookup_does_not_leak_across_tenants(): void
    {
        [$user1, $shop1] = $this->createManufacturerTenant();
        [$user2, $shop2] = $this->createManufacturerTenant();

        $this->createItem($shop2->id, null, ['barcode' => 'OTHERBC001']);

        $response = $this->actingAs($user1)->getJson('/api/item-by-barcode/OTHERBC001');

        $response->assertStatus(404);
    }

    // ── Price preview ───────────────────────────────────────────────

    public function test_manufacturer_price_preview_returns_correct_breakdown(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id, [
            'net_metal_weight' => 10.000,
            'purity' => 24.00,
            'wastage' => 0.500,
        ]);

        $response = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id,
            'customer_id' => $customer->id,
            'gold_rate' => 6000,
            'making' => 1000,
            'stone' => 500,
            'discount' => 0,
            'round_off' => 0,
        ]);

        $response->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('fine_weight', $data);
        $this->assertArrayHasKey('subtotal', $data);
        $this->assertArrayHasKey('gst', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('wastage_charge', $data);

        // fine_weight = 10 * (24/24) = 10
        $this->assertEquals(10.0, $data['fine_weight']);
        // subtotal = (10 * 6000) + 1000 + 500 = 61500
        $this->assertEquals(61500, $data['subtotal']);
    }

    public function test_retailer_price_preview_returns_selling_price_based(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 20000]);

        $response = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id,
            'customer_id' => $customer->id,
        ]);

        $response->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('selling_price', $data);
        $this->assertEquals(20000, $data['selling_price']);
        $this->assertEquals(600, $data['gst']);
    }

    // ── Authentication ──────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_pos(): void
    {
        $response = $this->get('/pos');
        $response->assertRedirect('/login');
    }
}

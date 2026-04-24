<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoiceAccountingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class InvoiceFlowTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── Invoice Listing ─────────────────────────────────────────────

    public function test_invoice_index_shows_shop_invoices(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);
        TenantContext::clear();

        $response = $this->actingAs($user)->get('/invoices');

        $response->assertOk();
        $response->assertViewHas('invoices');
        $response->assertViewHas('stats');
    }

    public function test_invoice_index_does_not_leak_across_tenants(): void
    {
        [$user1, $shop1] = $this->createManufacturerTenant();
        [$user2, $shop2] = $this->createManufacturerTenant();

        TenantContext::set($shop2->id);
        $customer2 = $this->createCustomer($shop2->id);
        InvoiceAccountingService::createDraft([
            'customer_id' => $customer2->id,
            'shop_id' => $shop2->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);
        TenantContext::clear();

        $response = $this->actingAs($user1)->get('/invoices');
        $response->assertOk();
        $invoices = $response->viewData('invoices');
        $this->assertEquals(0, $invoices->total());
    }

    // ── Invoice Immutability ────────────────────────────────────────

    public function test_finalized_invoice_cannot_be_updated(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id);

        $invoice = InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);

        InvoiceItem::record([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'weight' => 10,
            'rate' => 6000,
            'making_charges' => 500,
            'stone_amount' => 200,
            'line_total' => 60700,
        ]);

        $invoice = InvoiceAccountingService::finalizeDraft($invoice, 3.0);

        // Attempting to update a finalized invoice via forceFill should throw
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Finalized/cancelled invoices cannot be edited');
        $invoice->forceFill(['discount' => 5000])->save();
    }

    public function test_invoice_cannot_be_deleted(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        $invoice = InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invoices are immutable and cannot be deleted');
        $invoice->delete();
    }

    public function test_invoice_items_cannot_be_updated(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id);

        $invoice = InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);

        $invoiceItem = InvoiceItem::record([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'weight' => 10,
            'rate' => 6000,
            'making_charges' => 500,
            'stone_amount' => 200,
            'line_total' => 60700,
        ]);

        // Invoice items cannot be updated (via forceFill to bypass guarded)
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');
        $invoiceItem->forceFill(['line_total' => 99999])->save();
    }

    public function test_invoice_items_cannot_be_deleted(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id);

        $invoice = InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);

        $invoiceItem = InvoiceItem::record([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'weight' => 10,
            'rate' => 6000,
            'making_charges' => 500,
            'stone_amount' => 200,
            'line_total' => 60700,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');
        $invoiceItem->delete();
    }

    // ── Invoice Finalization ────────────────────────────────────────

    public function test_draft_invoice_gets_number_on_finalization(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id);

        $invoice = InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);

        $this->assertNull($invoice->invoice_number);

        InvoiceItem::record([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'weight' => 10,
            'rate' => 6000,
            'making_charges' => 500,
            'stone_amount' => 200,
            'line_total' => 60700,
        ]);

        $invoice = InvoiceAccountingService::finalizeDraft($invoice, 3.0);

        $this->assertNotNull($invoice->invoice_number);
        $this->assertStringStartsWith('INV-', $invoice->invoice_number);
        $this->assertEquals(Invoice::STATUS_FINALIZED, $invoice->status);
        $this->assertNotNull($invoice->finalized_at);
        $this->assertGreaterThan(0, (float) $invoice->subtotal);
        $this->assertGreaterThan(0, (float) $invoice->gst);
        $this->assertGreaterThan(0, (float) $invoice->total);
    }

    public function test_finalized_invoice_cannot_be_finalized_again(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id);

        $invoice = InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);

        InvoiceItem::record([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'weight' => 10,
            'rate' => 6000,
            'making_charges' => 500,
            'stone_amount' => 200,
            'line_total' => 60700,
        ]);

        $invoice = InvoiceAccountingService::finalizeDraft($invoice, 3.0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only draft invoices can be finalized');
        InvoiceAccountingService::finalizeDraft($invoice, 3.0);
    }

    public function test_draft_invoice_cannot_be_cancelled_via_reversal(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        $invoice = InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only finalized invoices can be cancelled');
        InvoiceAccountingService::cancelByReversal($invoice, 'Should fail');
    }

    // ── Audit Trail ─────────────────────────────────────────────────

    public function test_invoice_finalization_creates_audit_log(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id);

        $invoice = InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
        ]);

        InvoiceItem::record([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'weight' => 10,
            'rate' => 6000,
            'making_charges' => 500,
            'stone_amount' => 200,
            'line_total' => 60700,
        ]);

        InvoiceAccountingService::finalizeDraft($invoice, 3.0);

        $this->assertTrue(
            AuditLog::where('shop_id', $shop->id)
                ->where('action', 'invoice_finalized')
                ->where('model_id', $invoice->id)
                ->exists()
        );
    }

    // ── GST Calculation ─────────────────────────────────────────────

    public function test_gst_is_calculated_correctly_on_finalization(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id);

        $invoice = InvoiceAccountingService::createDraft([
            'customer_id' => $customer->id,
            'shop_id' => $shop->id,
            'gold_rate' => 6000,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 1000,
            'round_off' => 0,
        ]);

        // line_total = 10000 (simple number for easy math)
        InvoiceItem::record([
            'invoice_id' => $invoice->id,
            'item_id' => $item->id,
            'weight' => 10,
            'rate' => 6000,
            'making_charges' => 0,
            'stone_amount' => 0,
            'line_total' => 10000,
        ]);

        $invoice = InvoiceAccountingService::finalizeDraft($invoice, 3.0);

        // subtotal = 10000
        $this->assertEquals(10000, (float) $invoice->subtotal);
        // taxable = subtotal - discount = 10000 - 1000 = 9000
        // gst = 9000 * 3% = 270
        $this->assertEquals(270, (float) $invoice->gst);
        // total = subtotal + gst + wastage - discount + roundoff = 10000 + 270 + 0 - 1000 + 0 = 9270
        $this->assertEquals(9270, (float) $invoice->total);
    }

    // ── Authentication ──────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_view_invoices(): void
    {
        $response = $this->get('/invoices');
        $response->assertRedirect('/login');
    }
}

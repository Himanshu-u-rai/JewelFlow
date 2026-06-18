<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Item;
use App\Models\InstallmentPlan;
use App\Services\InstallmentService;
use App\Services\RetailerSalesService;
use App\Support\TenantContext;
use LogicException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * EMI from POS: starting an EMI draft sale must NOT 500. A prior implementation
 * set item status to 'reserved' — a value absent from the items_status_check DB
 * constraint (immediate check violation), and the EMI finalize step rejects any
 * item not 'in_stock' anyway. The correct behaviour is to leave items in_stock
 * for the draft; finalize moves them to sold.
 */
class EmiDraftSaleTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_emi_draft_sale_succeeds_and_items_stay_in_stock(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $this->actingAs($user);

        $invoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::prepareEmiDraftSale(
            customerId: $customer->id,
            itemIds: [$item->id],
        ));

        // The draft was created (no 500 / no constraint violation).
        $this->assertNotNull($invoice);
        $this->assertSame('draft', $invoice->status);

        // The item stays in_stock during the draft — not 'reserved'.
        $fresh = Item::query()->withoutGlobalScopes()->find($item->id);
        $this->assertSame('in_stock', $fresh->status, 'EMI draft must leave the item in_stock, not reserved.');
    }

    public function test_emi_draft_finalizes_end_to_end_and_item_becomes_sold(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $this->actingAs($user);

        $plan = TenantContext::runFor($shop->id, function () use ($customer, $item) {
            $draft = RetailerSalesService::prepareEmiDraftSale(
                customerId: $customer->id,
                itemIds: [$item->id],
            );

            // Finalize the draft into an EMI plan — this is the step that
            // requires items to still be in_stock (the reserve write would have
            // broken this) and moves them to sold.
            return app(InstallmentService::class)->finalizeDraftInvoiceToPlan(
                invoice: $draft,
                downPayment: 0.0,
                totalEmis: 6,
                interestRateAnnual: 0.0,
            );
        });

        $this->assertInstanceOf(InstallmentPlan::class, $plan);

        $fresh = Item::query()->withoutGlobalScopes()->find($item->id);
        $this->assertSame('sold', $fresh->status, 'After EMI finalize the item must be sold.');
    }

    public function test_cancelling_emi_discards_the_draft_and_items_stay_in_stock(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $this->actingAs($user);

        TenantContext::runFor($shop->id, function () use ($customer, $item) {
            $draft = RetailerSalesService::prepareEmiDraftSale(
                customerId: $customer->id,
                itemIds: [$item->id],
            );

            // Cancel → discard the draft (the behaviour behind the Cancel button).
            app(InstallmentService::class)->discardDraftPosEmiInvoice($draft);

            $fresh = Invoice::query()->withoutGlobalScopes()->find($draft->id);
            $this->assertSame(Invoice::STATUS_CANCELLED, $fresh->status, 'Discarded draft must be cancelled, not left as draft.');
        });

        // Item is untouched and still sellable.
        $freshItem = Item::query()->withoutGlobalScopes()->find($item->id);
        $this->assertSame('in_stock', $freshItem->status);
    }

    public function test_pos_emi_create_page_locks_to_the_passed_draft_only(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $itemA = $this->createItem($shop->id, $lot->id);
        $itemB = $this->createItem($shop->id, $lot->id);

        $this->actingAs($user);

        // Two POS-EMI drafts exist (the bug: both showed in the dropdown).
        [$draftA, $draftB] = TenantContext::runFor($shop->id, fn () => [
            RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$itemA->id]),
            RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$itemB->id]),
        ]);

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->get(route('installments.create', ['invoice_id' => $draftA->id, 'from_pos_emi' => 1])))
            ->assertOk()
            ->getContent();

        // The page is locked to draft A: no free invoice <select>, A shown,
        // and the OTHER draft (B) must NOT appear as a choice.
        $this->assertStringNotContainsString('<select name="invoice_id"', $html, 'POS EMI must not render an invoice dropdown.');
        $this->assertStringContainsString('value="' . $draftA->id . '"', $html);
        $this->assertStringNotContainsString('Draft #' . $draftB->id, $html, 'Other drafts must not be offered.');
    }

    public function test_emi_down_payment_records_the_chosen_upi_account(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $upi = \App\Models\ShopPaymentMethod::create([
            'shop_id' => $shop->id, 'type' => 'upi', 'name' => 'Shop GPay',
            'upi_id' => 'shop@upi', 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($user);

        $plan = TenantContext::runFor($shop->id, function () use ($customer, $item, $upi) {
            $draft = RetailerSalesService::prepareEmiDraftSale(
                customerId: $customer->id,
                itemIds: [$item->id],
            );

            return app(InstallmentService::class)->finalizeDraftInvoiceToPlan(
                invoice: $draft,
                downPayment: 5000.0,
                totalEmis: 6,
                interestRateAnnual: 0.0,
                downPaymentMethod: 'upi',
                downPaymentReference: 'TXN123',
                downPaymentMethodId: $upi->id,
            );
        });

        // The down-payment InvoicePayment must carry the chosen UPI account.
        $payment = \App\Models\InvoicePayment::query()->withoutGlobalScopes()
            ->where('invoice_id', $plan->invoice_id)
            ->where('mode', 'upi')
            ->first();

        $this->assertNotNull($payment, 'A UPI down-payment row should exist.');
        $this->assertSame($upi->id, (int) $payment->payment_method_id, 'Down payment must link to the chosen UPI account.');
        $this->assertEqualsWithDelta(5000.0, (float) $payment->amount, 0.001);
    }

    public function test_discard_refuses_a_finalized_invoice(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $this->actingAs($user);

        TenantContext::runFor($shop->id, function () use ($customer, $item) {
            $draft = RetailerSalesService::prepareEmiDraftSale(
                customerId: $customer->id,
                itemIds: [$item->id],
            );
            // Finalize into a plan (now it's a real EMI, not a discardable draft).
            app(InstallmentService::class)->finalizeDraftInvoiceToPlan(
                invoice: $draft,
                downPayment: 0.0,
                totalEmis: 6,
                interestRateAnnual: 0.0,
            );

            $this->expectException(LogicException::class);
            app(InstallmentService::class)->discardDraftPosEmiInvoice($draft->fresh());
        });
    }
}

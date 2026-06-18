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

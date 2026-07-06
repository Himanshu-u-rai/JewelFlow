<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\RetailerSalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression: a retailer sale carrying an invoice-level discount must push that
 * discount DOWN onto each invoice line (allocated_discount). GST was already
 * apportioned per line; the discount was not, so lines overstated their value
 * by the discount amount.
 *
 * The money-path symptom (found in live endurance testing): returning a
 * discounted line refunded the GROSS line_total instead of the net paid amount,
 * over-refunding by the discount and breaking the invariant
 *   CreditNote.total == Σ ReturnLineItem.refund_total.
 *
 * The fix populates allocated_discount at sale time; the return resolver already
 * subtracts it, so seeding it correctly repairs the whole chain at the source.
 */
class RetailerDiscountAllocationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** Σ allocated_discount == invoice.discount, and the line identity holds. */
    public function test_invoice_discount_is_apportioned_across_lines(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $a = $this->createItem($shop->id, null, ['selling_price' => 40000]);
        $b = $this->createItem($shop->id, null, ['selling_price' => 20000]);

        $this->actingAs($owner);
        $invoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$a->id, $b->id], discount: 3000,
        ));

        $lines = InvoiceItem::where('invoice_id', $invoice->id)->get();

        // 1. The whole discount is distributed — no more, no less.
        $this->assertEqualsWithDelta(
            (float) $invoice->discount,
            (float) $lines->sum('allocated_discount'),
            0.005,
            'invoice discount must fully allocate to lines'
        );
        $this->assertEqualsWithDelta(3000.0, (float) $lines->sum('allocated_discount'), 0.005);

        // 2. The 40k line carries the larger share (proportional apportionment).
        $bigLine = $lines->firstWhere('item_id', $a->id);
        $this->assertGreaterThan(
            (float) $lines->firstWhere('item_id', $b->id)->allocated_discount,
            (float) $bigLine->allocated_discount,
        );

        // 3. Line identity reconciles to the invoice header:
        //    Σ(line_total − allocated_discount) + Σgst + round_off == invoice.total
        $net = $lines->sum(fn ($l) => (float) $l->line_total - (float) $l->allocated_discount);
        $recomputed = round($net + (float) $lines->sum('gst_amount') + (float) $invoice->round_off, 2);
        $this->assertEqualsWithDelta((float) $invoice->total, $recomputed, 0.02, 'line net + gst == invoice total');
    }

    /** No discount → every line's allocated_discount stays zero (legacy parity). */
    public function test_no_discount_leaves_allocation_zero(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 25000]);

        $this->actingAs($owner);
        $invoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$item->id],
        ));

        $this->assertEqualsWithDelta(
            0.0,
            (float) InvoiceItem::where('invoice_id', $invoice->id)->sum('allocated_discount'),
            0.005,
        );
    }
}

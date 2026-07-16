<?php

namespace Tests\Feature\Returns;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\LoyaltyTransaction;
use App\Models\ShopPreferences;
use App\Services\InvoiceAccountingService;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Loyalty reversal copy on a return (defect D4).
 *
 * Reversing loyalty points earned on an invoice is shared by two flows: an
 * invoice cancellation and a sale return. The ledger note must describe what
 * actually happened — a return should NOT read "invoice cancelled". Only the
 * label differs; the reversed points and balances are identical either way.
 */
class LoyaltyReversalCopyTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function finalizedInvoice(int $shopId, int $customerId, Item $item): Invoice
    {
        ShopPreferences::withoutTenant()->updateOrCreate(
            ['shop_id' => $shopId],
            [
                'refund_making_charges' => true, 'refund_stone_charges' => true,
                'refund_gst' => true, 'wear_loss_pct' => 0, 'restocking_fee_pct' => 0,
                'return_settlement_mode' => 'cash_or_credit',
            ],
        );

        return TenantContext::runFor($shopId, function () use ($shopId, $customerId, $item) {
            $draft = new Invoice();
            $draft->forceFill([
                'shop_id' => $shopId, 'customer_id' => $customerId,
                'gold_rate' => 7200, 'subtotal' => 0, 'gst' => 0, 'gst_rate' => 3,
                'wastage_charge' => 0, 'discount' => 0, 'round_off' => 0, 'total' => 0,
                'status' => Invoice::STATUS_DRAFT,
            ])->save();

            $price = (float) $item->selling_price;
            InvoiceItem::record([
                'invoice_id' => $draft->id, 'item_id' => $item->id,
                'metal_type' => $item->metal_type, 'weight' => (float) $item->gross_weight,
                'rate' => 0, 'making_charges' => (float) $item->making_charges,
                'stone_amount' => (float) $item->stone_charges, 'hallmark_charges' => 0,
                'line_total' => $price, 'gst_rate' => 3,
                'gst_amount' => round($price * 0.03, 2),
                'allocated_discount' => 0, 'allocated_round_off' => 0, 'allocated_loyalty_pts' => 0,
            ]);

            return InvoiceAccountingService::finalizeDraft($draft);
        });
    }

    public function test_return_reversal_note_describes_a_return_not_a_cancellation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 55000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, $item);

        // Points must have been earned on finalize, else nothing to reverse.
        $earned = LoyaltyTransaction::withoutTenant()
            ->where('invoice_id', $inv->id)->where('type', 'earn')->sum('points');
        $this->assertGreaterThan(0, $earned, 'invoice should have earned loyalty points');

        TenantContext::runFor($shop->id, fn () => app(ReturnService::class)->createFullReturn(
            $inv, 'changed mind', $owner->id, false, ReturnService::REFUND_SETTLEMENT_STORE_CREDIT));

        $reversal = LoyaltyTransaction::withoutTenant()
            ->where('invoice_id', $inv->id)->where('type', 'redeem')->latest('id')->first();

        $this->assertNotNull($reversal, 'a reversal transaction was written');
        $this->assertSame('Reversed — sale returned', $reversal->description);
        $this->assertStringNotContainsString('cancelled', $reversal->description);
        // Semantics unchanged: the full earned amount is reversed.
        $this->assertSame((int) $earned, (int) $reversal->points);
    }
}

<?php

namespace Tests\Feature\Returns;

use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ReturnOrder;
use App\Models\ShopPreferences;
use App\Models\StoreCreditMovement;
use App\Services\InvoiceAccountingService;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Credit-note settlement method — display vs accounting guard (defect D3).
 *
 * The refund settlement (cash vs store credit) drove the money path correctly
 * but was never persisted on the return order, so the credit-note view fell
 * back to "Cash refund" for every return, including store-credit ones. These
 * tests pin BOTH halves: the accounting/ledger method AND the rendered label,
 * plus the legacy-row derivation from the authoritative store-credit ledger.
 */
class CreditNoteSettlementLabelTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private ReturnService $returns;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->returns = app(ReturnService::class);
    }

    private function configurePolicy(int $shopId): void
    {
        ShopPreferences::withoutTenant()->updateOrCreate(
            ['shop_id' => $shopId],
            [
                'refund_making_charges' => true, 'refund_stone_charges' => true,
                'refund_gst' => true, 'wear_loss_pct' => 0, 'restocking_fee_pct' => 0,
                'return_settlement_mode' => 'cash_or_credit',
            ],
        );
    }

    private function finalizedInvoice(int $shopId, int $customerId, Item $item): Invoice
    {
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

    private function cnCashRows(int $shopId): int
    {
        return CashTransaction::withoutTenant()->where('shop_id', $shopId)
            ->where('source_type', 'credit_note')->count();
    }

    public function test_store_credit_return_persists_method_and_renders_store_credit(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 55000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, $item);

        $return = TenantContext::runFor($shop->id, fn () => $this->returns->createFullReturn(
            $inv, 'store credit please', $owner->id, false, ReturnService::REFUND_SETTLEMENT_STORE_CREDIT));

        // Accounting: wallet credited, no cash-out.
        $this->assertSame(0, $this->cnCashRows($shop->id), 'store-credit settlement fires no refund cash-out');
        $this->assertTrue(
            StoreCreditMovement::withoutTenant()->where('shop_id', $shop->id)
                ->where('source_type', StoreCreditMovement::SOURCE_CREDIT_NOTE_ISSUED)->exists(),
            'a store-credit ledger movement was written'
        );

        // Persisted method + rendered label.
        $fresh = ReturnOrder::withoutTenant()->with('creditNote')->findOrFail($return->id);
        $this->assertSame(ReturnOrder::SETTLEMENT_STORE_CREDIT, $fresh->refund_settlement);
        $this->assertSame('Store credit', $fresh->settlementMethodLabel());
    }

    public function test_cash_return_persists_method_and_renders_cash_refund(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 55000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, $item);

        $return = TenantContext::runFor($shop->id, fn () => $this->returns->createFullReturn(
            $inv, 'cash back', $owner->id, false, ReturnService::REFUND_SETTLEMENT_CASH));

        $this->assertSame(1, $this->cnCashRows($shop->id), 'cash settlement fires one refund cash-out');

        $fresh = ReturnOrder::withoutTenant()->with('creditNote')->findOrFail($return->id);
        $this->assertSame(ReturnOrder::SETTLEMENT_CASH, $fresh->refund_settlement);
        $this->assertSame('Cash refund', $fresh->settlementMethodLabel());
    }

    public function test_legacy_null_method_derives_store_credit_from_ledger(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 55000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, $item);

        $return = TenantContext::runFor($shop->id, fn () => $this->returns->createFullReturn(
            $inv, 'store credit please', $owner->id, false, ReturnService::REFUND_SETTLEMENT_STORE_CREDIT));

        // Simulate a legacy row settled before refund_settlement was persisted.
        DB::table('return_orders')->where('id', $return->id)->update(['refund_settlement' => null]);

        $fresh = TenantContext::runFor($shop->id, fn () => ReturnOrder::with('creditNote')->findOrFail($return->id));
        $this->assertNull($fresh->refund_settlement);
        $this->assertSame('Store credit', $fresh->settlementMethodLabel(), 'derived from the store-credit ledger');
    }
}

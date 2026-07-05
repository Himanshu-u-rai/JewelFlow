<?php

namespace Tests\Feature\Returns;

use App\Models\CashTransaction;
use App\Models\ExchangeOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ReturnLineItem;
use App\Models\ReturnedItemDisposition;
use App\Models\ShopPreferences;
use App\Services\InvoiceAccountingService;
use App\Services\Returns\ExchangeService;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Retailer exchange cashbook settlement — money-path guard.
 *
 * Regression: an exchange whose return half covers EVERY line of a single-line
 * invoice was delegated to createFullReturn(), which has no $forExchange
 * awareness and unconditionally fired a compensating cash-out ('credit_note').
 * ExchangeService then ALSO recorded its net settlement ('exchange_order') — so
 * the drawer double-counted (CN refund + net, no offsetting new-sale cash-in).
 *
 * The fix keeps exchanges in the partial path (which honors $forExchange and
 * skips return-side cash), so the ONLY cash the exchange writes is the single
 * net settlement. Standalone returns are unaffected — they still fire cash.
 */
class ExchangeCashSettlementTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private ReturnService $returns;
    private ExchangeService $exchange;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->returns  = app(ReturnService::class);
        $this->exchange = app(ExchangeService::class);
    }

    /** Refund-everything policy so refunds compute cleanly. */
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

    /**
     * Build a FINALIZED invoice with one line per given item — mirrors
     * ExchangeService::createNewSaleInvoice, avoiding POS/pricing-preview setup.
     *
     * @param  array<Item>  $items
     */
    private function finalizedInvoice(int $shopId, int $customerId, array $items): Invoice
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $customerId, $items) {
            $draft = new Invoice();
            $draft->forceFill([
                'shop_id' => $shopId, 'customer_id' => $customerId,
                'gold_rate' => 7200, 'subtotal' => 0, 'gst' => 0, 'gst_rate' => 3,
                'wastage_charge' => 0, 'discount' => 0, 'round_off' => 0, 'total' => 0,
                'status' => Invoice::STATUS_DRAFT,
            ])->save();

            foreach ($items as $item) {
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
            }

            return InvoiceAccountingService::finalizeDraft($draft);
        });
    }

    private function cnCashRows(int $shopId): int
    {
        return CashTransaction::withoutTenant()->where('shop_id', $shopId)
            ->where('source_type', 'credit_note')->count();
    }

    private function exchangeCashRows(int $shopId)
    {
        return CashTransaction::withoutTenant()->where('shop_id', $shopId)
            ->where('source_type', 'exchange_order')->get();
    }

    private function selection(InvoiceItem $line): array
    {
        return [$line->id => [
            'condition' => ReturnLineItem::CONDITION_GOOD,
            'disposition' => ReturnedItemDisposition::DISPOSITION_RESTOCKED,
        ]];
    }

    // ── standalone returns must STILL fire refund cash ───────────────────────

    /** 1. Standalone full return writes exactly one refund cash-out. */
    public function test_standalone_full_return_writes_one_refund_cash_out(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 55000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, [$item]);

        TenantContext::runFor($shop->id, fn () => $this->returns->createFullReturn($inv, 'change of mind', $owner->id));

        $this->assertSame(1, $this->cnCashRows($shop->id), 'full return still fires one refund cash-out');
    }

    /** 2. Standalone partial return (subset of lines) writes one refund cash-out. */
    public function test_standalone_partial_return_writes_one_refund_cash_out(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $a = $this->createItem($shop->id, null, ['selling_price' => 40000]);
        $b = $this->createItem($shop->id, null, ['selling_price' => 20000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, [$a, $b]);

        $lineA = TenantContext::runFor($shop->id, fn () => InvoiceItem::where('invoice_id', $inv->id)->firstOrFail());

        TenantContext::runFor($shop->id, fn () => $this->returns->createPartialReturn(
            $inv, $this->selection($lineA), 'one item back', $owner->id));

        $this->assertSame(1, $this->cnCashRows($shop->id), 'partial return still fires one refund cash-out');
    }

    // ── exchanges must NOT double-count ──────────────────────────────────────

    /** 3+4. Single-line invoice exchange writes ONLY the net settlement — no CN cash. */
    public function test_single_line_exchange_writes_only_net_settlement(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $sold = $this->createItem($shop->id, null, ['selling_price' => 55000]);
        $newItem = $this->createItem($shop->id, null, ['selling_price' => 3000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, [$sold]);

        $exchange = TenantContext::runFor($shop->id, fn () => $this->exchange->createUnified(
            $inv, $this->selection($inv->items()->first()), [$newItem->id],
            ExchangeOrder::BASIS_SALE_DAY_RATE, 'upgrade down', $owner->id));

        // No return-side cash: the delegated full-return path must not have fired.
        $this->assertSame(0, $this->cnCashRows($shop->id), 'exchange must not fire a credit_note refund cash-out');

        // Exactly one net settlement, equal to the exchange net (new − CN, negative → cash out).
        $rows = $this->exchangeCashRows($shop->id);
        $this->assertCount(1, $rows, 'exactly one net settlement cash row');
        $this->assertEqualsWithDelta(abs((float) $exchange->net_amount), (float) $rows->first()->amount, 0.005);
        $this->assertSame('out', $rows->first()->type, 'returned value > new value → net cash out');
    }

    /** 5. Multi-line invoice exchange (returning all lines) also nets once — no CN cash. */
    public function test_multi_line_exchange_returning_all_lines_nets_once(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $a = $this->createItem($shop->id, null, ['selling_price' => 30000]);
        $b = $this->createItem($shop->id, null, ['selling_price' => 25000]);
        $newItem = $this->createItem($shop->id, null, ['selling_price' => 4000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, [$a, $b]);

        $selections = TenantContext::runFor($shop->id, function () use ($inv) {
            $sel = [];
            foreach (InvoiceItem::where('invoice_id', $inv->id)->get() as $line) {
                $sel[$line->id] = [
                    'condition' => ReturnLineItem::CONDITION_GOOD,
                    'disposition' => ReturnedItemDisposition::DISPOSITION_RESTOCKED,
                ];
            }
            return $sel;
        });

        TenantContext::runFor($shop->id, fn () => $this->exchange->createUnified(
            $inv, $selections, [$newItem->id],
            ExchangeOrder::BASIS_SALE_DAY_RATE, 'trade in two', $owner->id));

        $this->assertSame(0, $this->cnCashRows($shop->id), 'all-line exchange must not fire credit_note cash');
        $this->assertCount(1, $this->exchangeCashRows($shop->id), 'exactly one net settlement cash row');
    }
}

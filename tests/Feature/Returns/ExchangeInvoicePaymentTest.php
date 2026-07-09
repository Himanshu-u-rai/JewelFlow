<?php

namespace Tests\Feature\Returns;

use App\Models\CashTransaction;
use App\Models\ExchangeOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Item;
use App\Models\ReturnLineItem;
use App\Models\ReturnedItemDisposition;
use App\Models\ShopPreferences;
use App\Reporting\ReportPeriod;
use App\Reporting\SalesService;
use App\Services\InvoiceAccountingService;
use App\Services\Returns\ExchangeService;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Retailer exchange — invoice payment ledger settlement (Round 2 P1).
 *
 * Bug: ExchangeService::createUnified() finalized the new sale invoice but wrote
 * NO InvoicePayment rows. Since Payment Reconciliation derives collected as
 * Σ invoice_payments, a fully-settled exchange invoice showed Received ₹0 /
 * Outstanding = total / status Unpaid, even though the cashbook net was correct.
 *
 * Correct accounting: the new invoice must be fully paid when the exchange
 * settles, split into:
 *   - an internal exchange-credit payment (MODE_OTHER) up to the returned credit
 *     applied to the new invoice = min(cn.total, invoice.total)
 *   - a real cash payment (MODE_CASH) for the customer-paid difference =
 *     max(invoice.total - cn.total, 0)
 * Together Σ invoice_payments == invoice.total. Cashbook still records ONLY the
 * net movement (no fake cash for the internal credit, no double-count).
 */
class ExchangeInvoicePaymentTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private ReturnService $returns;
    private ExchangeService $exchange;
    private SalesService $sales;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->returns  = app(ReturnService::class);
        $this->exchange = app(ExchangeService::class);
        $this->sales    = app(SalesService::class);
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

    /** FINALIZED invoice with one line per item — mirrors createNewSaleInvoice. */
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

    private function selection(InvoiceItem $line): array
    {
        return [$line->id => [
            'condition' => ReturnLineItem::CONDITION_GOOD,
            'disposition' => ReturnedItemDisposition::DISPOSITION_RESTOCKED,
        ]];
    }

    /** @return \Illuminate\Support\Collection<int,InvoicePayment> */
    private function paymentsFor(Invoice $invoice)
    {
        return InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)->get();
    }

    private function paidTotal(Invoice $invoice): float
    {
        return (float) InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)->sum('amount');
    }

    private function modeSum(Invoice $invoice, string $mode): float
    {
        return (float) InvoicePayment::withoutTenant()
            ->where('invoice_id', $invoice->id)->where('mode', $mode)->sum('amount');
    }

    private function exchangeCashRows(int $shopId)
    {
        return CashTransaction::withoutTenant()->where('shop_id', $shopId)
            ->where('source_type', 'exchange_order')->get();
    }

    /** Assert the reconciliation report marks the new invoice fully paid. */
    private function assertReconciledPaid(int $shopId, Invoice $invoice): void
    {
        $data = $this->sales->paymentReconciliation($shopId, ReportPeriod::range('2000-01-01', '2100-01-01'));
        $row = $data->rows->firstWhere('invoice_number', $invoice->invoice_number);
        $this->assertNotNull($row, 'new invoice appears in reconciliation');
        $this->assertSame('paid', $row->status, 'exchange invoice must reconcile as paid, not unpaid');
        $this->assertEqualsWithDelta(0, (float) $row->pending, 0.01, 'no outstanding on a settled exchange');
        $this->assertEqualsWithDelta((float) $invoice->total, (float) $row->collected, 0.01);
    }

    // ── Case 1: customer pays the difference (new > returned credit) ─────────

    public function test_customer_pays_difference_splits_credit_and_cash(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $returned = $this->createItem($shop->id, null, ['selling_price' => 10000]);
        $newItem  = $this->createItem($shop->id, null, ['selling_price' => 80000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, [$returned]);

        $exchange = TenantContext::runFor($shop->id, fn () => $this->exchange->createUnified(
            $inv, $this->selection($inv->items()->first()), [$newItem->id],
            ExchangeOrder::BASIS_SALE_DAY_RATE, 'upgrade', $owner->id));

        $newInvoice = $exchange->newInvoice;
        $cnTotal = (float) $exchange->returnOrder->creditNote->total;
        $invTotal = (float) $newInvoice->total;
        $this->assertGreaterThan($cnTotal, $invTotal, 'sanity: new invoice exceeds returned credit');

        // Ledger fully pays the invoice.
        $this->assertEqualsWithDelta($invTotal, $this->paidTotal($newInvoice), 0.01,
            'Σ invoice_payments must equal invoice total');
        // Credit portion = returned credit; cash portion = the difference.
        $this->assertEqualsWithDelta($cnTotal, $this->modeSum($newInvoice, InvoicePayment::MODE_OTHER), 0.01);
        $this->assertEqualsWithDelta($invTotal - $cnTotal, $this->modeSum($newInvoice, InvoicePayment::MODE_CASH), 0.01);

        $this->assertReconciledPaid($shop->id, $newInvoice);

        // Cashbook: exactly one net settlement, cash IN = the difference. No fake cash for credit.
        $rows = $this->exchangeCashRows($shop->id);
        $this->assertCount(1, $rows);
        $this->assertSame('in', $rows->first()->type);
        $this->assertEqualsWithDelta(abs((float) $exchange->net_amount), (float) $rows->first()->amount, 0.01);
        $this->assertEqualsWithDelta($invTotal - $cnTotal, (float) $rows->first()->amount, 0.01,
            'cash movement equals only the customer-paid difference');
    }

    // ── Case 2: shop refunds the difference (returned credit > new) ──────────

    public function test_shop_refunds_difference_pays_invoice_by_credit_only(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $returned = $this->createItem($shop->id, null, ['selling_price' => 80000]);
        $newItem  = $this->createItem($shop->id, null, ['selling_price' => 10000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, [$returned]);

        $exchange = TenantContext::runFor($shop->id, fn () => $this->exchange->createUnified(
            $inv, $this->selection($inv->items()->first()), [$newItem->id],
            ExchangeOrder::BASIS_SALE_DAY_RATE, 'downgrade', $owner->id));

        $newInvoice = $exchange->newInvoice;
        $invTotal = (float) $newInvoice->total;
        $cnTotal = (float) $exchange->returnOrder->creditNote->total;
        $this->assertGreaterThan($invTotal, $cnTotal, 'sanity: returned credit exceeds new invoice');

        // Invoice fully paid by exchange credit; no cash-mode payment.
        $this->assertEqualsWithDelta($invTotal, $this->paidTotal($newInvoice), 0.01);
        $this->assertEqualsWithDelta($invTotal, $this->modeSum($newInvoice, InvoicePayment::MODE_OTHER), 0.01,
            'credit applied is clamped to invoice total (no over-payment)');
        $this->assertEqualsWithDelta(0, $this->modeSum($newInvoice, InvoicePayment::MODE_CASH), 0.01,
            'customer paid nothing — no cash-mode invoice payment');

        $this->assertReconciledPaid($shop->id, $newInvoice);

        // Cashbook: one refund OUT for the net excess credit only.
        $rows = $this->exchangeCashRows($shop->id);
        $this->assertCount(1, $rows);
        $this->assertSame('out', $rows->first()->type);
        $this->assertEqualsWithDelta(abs((float) $exchange->net_amount), (float) $rows->first()->amount, 0.01);
    }

    // ── Case 3: equal exchange (returned credit == new invoice) ──────────────

    public function test_equal_exchange_pays_invoice_by_credit_no_cash(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $returned = $this->createItem($shop->id, null, ['selling_price' => 50000]);
        $newItem  = $this->createItem($shop->id, null, ['selling_price' => 50000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, [$returned]);

        $exchange = TenantContext::runFor($shop->id, fn () => $this->exchange->createUnified(
            $inv, $this->selection($inv->items()->first()), [$newItem->id],
            ExchangeOrder::BASIS_SALE_DAY_RATE, 'like for like', $owner->id));

        $newInvoice = $exchange->newInvoice;
        $invTotal = (float) $newInvoice->total;
        $this->assertEqualsWithDelta(0, (float) $exchange->net_amount, 0.01, 'sanity: equal exchange nets to zero');

        $this->assertEqualsWithDelta($invTotal, $this->paidTotal($newInvoice), 0.01);
        $this->assertEqualsWithDelta($invTotal, $this->modeSum($newInvoice, InvoicePayment::MODE_OTHER), 0.01);
        $this->assertEqualsWithDelta(0, $this->modeSum($newInvoice, InvoicePayment::MODE_CASH), 0.01);

        $this->assertReconciledPaid($shop->id, $newInvoice);

        // No net movement → no exchange cashbook row.
        $this->assertCount(0, $this->exchangeCashRows($shop->id), 'equal exchange writes no cash');
    }

    // ── Regression: the exact bug class ──────────────────────────────────────

    /** A completed exchange invoice must satisfy Σ invoice_payments == invoice.total. */
    public function test_completed_exchange_invoice_is_fully_settled(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $returned = $this->createItem($shop->id, null, ['selling_price' => 12000]);
        $newItem  = $this->createItem($shop->id, null, ['selling_price' => 45000]);
        $inv = $this->finalizedInvoice($shop->id, $customer->id, [$returned]);

        $exchange = TenantContext::runFor($shop->id, fn () => $this->exchange->createUnified(
            $inv, $this->selection($inv->items()->first()), [$newItem->id],
            ExchangeOrder::BASIS_SALE_DAY_RATE, 'regression', $owner->id));

        $newInvoice = $exchange->newInvoice;
        $this->assertGreaterThan(0, $this->paymentsFor($newInvoice)->count(), 'exchange invoice must have payments');
        $this->assertEqualsWithDelta((float) $newInvoice->total, $this->paidTotal($newInvoice), 0.01);
        $this->assertReconciledPaid($shop->id, $newInvoice);
    }
}

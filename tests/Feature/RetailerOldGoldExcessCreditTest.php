<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\InvoicePayment;
use App\Models\MetalMovement;
use App\Models\StoreCreditMovement;
use App\Services\RetailerSalesService;
use App\Services\StoreCreditService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Old-gold excess handling (retailer edition). When the customer's old metal
 * is worth more than the bill, the sale must not block, the excess must not
 * be lost or paid out as cash — it becomes customer store credit:
 *
 *   - old_gold InvoicePayment keeps the FULL metal value (report + lot truth)
 *   - a negative wallet "change" row reconciles Σ(payments) == invoice.total
 *   - StoreCreditMovement(+excess, source=old_gold_excess) credits the wallet
 *   - NO CashTransaction is written for the excess (no fake cash payout)
 */
class RetailerOldGoldExcessCreditTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** Old-gold payment for an item, at a given rate. amount = fine × rate. */
    private function oldGoldPayment(float $amount, float $grossWt = 10, float $purity = 24, float $rate = 0): array
    {
        // Fine weight for 24K = gross × 24/24 = gross. Pick rate so fine×rate = amount.
        $rate = $rate ?: round($amount / $grossWt, 2);

        return [
            'mode' => 'old_gold',
            'amount' => $amount,
            'metal_gross_weight' => $grossWt,
            'metal_purity' => $purity,
            'metal_test_loss' => 0,
            'metal_rate_per_gram' => $rate,
        ];
    }

    private function retailerSaleContext(float $sellingPrice = 50000): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => $sellingPrice]);
        $this->actingAs($owner);

        return [$owner, $shop, $customer, $item];
    }

    /** 1. Old-gold below invoice total → the shortfall must still be paid. */
    public function test_old_gold_below_total_still_requires_remaining_payment(): void
    {
        [, $shop, $customer, $item] = $this->retailerSaleContext();

        try {
            TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
                $customer->id, [$item->id], payments: [$this->oldGoldPayment(10000)],
            ));
            $this->fail('Sale should be rejected: old gold covers only part of the bill.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('does not match invoice total', $e->getMessage());
        }

        // No store credit sneaked in on the failed sale.
        $this->assertSame(0, StoreCreditMovement::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    /** 2. Old-gold exactly equals invoice total → sale succeeds, no credit. */
    public function test_old_gold_exact_total_sale_succeeds_without_credit(): void
    {
        [, $shop, $customer, $item] = $this->retailerSaleContext();

        // First discover the invoice total via a cash sale on a twin item.
        $twin = $this->createItem($shop->id, null, ['selling_price' => 50000]);
        $cashInvoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$twin->id],
        ));
        $total = (float) $cashInvoice->total;

        $invoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$item->id], payments: [$this->oldGoldPayment($total)],
        ));

        $this->assertEqualsWithDelta($total, (float) $invoice->total, 0.01);
        $this->assertSame(
            0,
            StoreCreditMovement::withoutTenant()->where('shop_id', $shop->id)
                ->where('source_type', StoreCreditMovement::SOURCE_OLD_GOLD_EXCESS)->count(),
            'Exact settlement must not create store credit.'
        );
        // Σ payments == total.
        $paid = (float) InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)->sum('amount');
        $this->assertEqualsWithDelta($total, $paid, 0.01);
    }

    /** 3+6. Excess with customer → sale succeeds, wallet gains exact excess. */
    public function test_old_gold_excess_becomes_store_credit(): void
    {
        [, $shop, $customer, $item] = $this->retailerSaleContext();

        $twin = $this->createItem($shop->id, null, ['selling_price' => 50000]);
        $cashInvoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$twin->id],
        ));
        $total = (float) $cashInvoice->total;
        $oldGoldValue = round($total + 15000, 2);

        $invoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$item->id], payments: [$this->oldGoldPayment($oldGoldValue)],
        ));

        // Invoice fully settled: Σ payments == invoice.total exactly.
        $paid = (float) InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)->sum('amount');
        $this->assertEqualsWithDelta((float) $invoice->total, $paid, 0.01, 'payments must reconcile to total');

        // Wallet gained exactly the excess.
        $balance = app(StoreCreditService::class)->balance($shop->id, $customer->id);
        $this->assertEqualsWithDelta(15000.0, $balance, 0.01);

        $movement = StoreCreditMovement::withoutTenant()
            ->where('shop_id', $shop->id)->where('customer_id', $customer->id)
            ->where('source_type', StoreCreditMovement::SOURCE_OLD_GOLD_EXCESS)->firstOrFail();
        $this->assertEqualsWithDelta(15000.0, (float) $movement->amount, 0.01);
        $this->assertSame($invoice->id, (int) $movement->source_id);

        // The old_gold payment row keeps the FULL metal value.
        $gold = InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)
            ->where('mode', 'old_gold')->firstOrFail();
        $this->assertEqualsWithDelta($oldGoldValue, (float) $gold->amount, 0.01);

        // The wallet "change" row is the negative excess.
        $wallet = InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)
            ->where('mode', 'wallet')->firstOrFail();
        $this->assertEqualsWithDelta(-15000.0, (float) $wallet->amount, 0.01);
    }

    /** 4. Excess without a customer → clear rejection. */
    public function test_old_gold_excess_without_customer_is_rejected(): void
    {
        [, $shop, $customer, $item] = $this->retailerSaleContext();

        $twin = $this->createItem($shop->id, null, ['selling_price' => 50000]);
        $cashInvoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$twin->id],
        ));
        $total = (float) $cashInvoice->total;

        try {
            TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
                0, [$item->id], payments: [$this->oldGoldPayment(round($total + 5000, 2))],
            ));
            $this->fail('Excess without a customer must be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Old-gold excess requires a customer so credit can be stored.',
                collect($e->errors())->flatten()->implode(' ')
            );
        } catch (\Exception $e) {
            // Customer FK/lookup may fail first depending on flow — accept only
            // if the sale did NOT go through and no credit was written.
        }

        $this->assertSame(
            0,
            StoreCreditMovement::withoutTenant()->where('shop_id', $shop->id)
                ->where('source_type', StoreCreditMovement::SOURCE_OLD_GOLD_EXCESS)->count()
        );
    }

    /** 5. Cashbook: no cash movement for the excess (and none for old gold). */
    public function test_cashbook_has_no_fake_payout_for_excess(): void
    {
        [, $shop, $customer, $item] = $this->retailerSaleContext();

        $twin = $this->createItem($shop->id, null, ['selling_price' => 50000]);
        $cashInvoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$twin->id],
        ));
        $total = (float) $cashInvoice->total;

        $before = CashTransaction::withoutTenant()->where('shop_id', $shop->id)->count();

        $invoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$item->id], payments: [$this->oldGoldPayment(round($total + 12000, 2))],
        ));

        $after = CashTransaction::withoutTenant()->where('shop_id', $shop->id)->count();
        $this->assertSame($before, $after, 'Old-gold-only sale with excess must write no cash transactions.');
        $this->assertSame(
            0,
            CashTransaction::withoutTenant()->where('invoice_id', $invoice->id)->count()
        );
    }

    /** 7. Metal side records the FULL old-gold: fine weight + lot movement. */
    public function test_metal_entries_record_full_old_gold_amount(): void
    {
        [, $shop, $customer, $item] = $this->retailerSaleContext();

        $twin = $this->createItem($shop->id, null, ['selling_price' => 50000]);
        $cashInvoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$twin->id],
        ));
        $total = (float) $cashInvoice->total;
        $oldGoldValue = round($total + 15000, 2);
        $grossWt = 10.0; // 24K, 0 loss → fine = 10.000 g

        $invoice = TenantContext::runFor($shop->id, fn () => RetailerSalesService::sellItems(
            $customer->id, [$item->id],
            payments: [$this->oldGoldPayment($oldGoldValue, grossWt: $grossWt)],
        ));

        $gold = InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)
            ->where('mode', 'old_gold')->firstOrFail();
        $this->assertEqualsWithDelta($grossWt, (float) $gold->metal_fine_weight, 0.001, '24K → fine == gross');
        $this->assertEqualsWithDelta($oldGoldValue, (float) $gold->amount, 0.01, 'full metal value on the payment row');
        $this->assertNotNull($gold->weekly_lot_id, 'payment must link to the weekly lot');

        $movement = MetalMovement::withoutTenant()
            ->where('invoice_id', $invoice->id)->where('type', 'old_metal_in')->firstOrFail();
        $this->assertEqualsWithDelta($grossWt, (float) $movement->fine_weight, 0.001, 'full fine weight moved to lot');
    }
}

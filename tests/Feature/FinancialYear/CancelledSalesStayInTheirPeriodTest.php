<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ShopPreferences;
use App\Reporting\ProfitReportingService;
use App\Reporting\ReportPeriod;
use App\Services\InvoiceAccountingService;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A bill stays a sale in the period it was issued; a later cancellation or return is a separate negative entry in
 * ITS period (how GST returns and the books already filed see it). Found by the GST year-report work 2026-11-16:
 * cancelled originals were dropped from their own period AND the reversal/credit note was subtracted too.
 */
class CancelledSalesStayInTheirPeriodTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private $owner;
    private $shop;
    private $customer;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->customer = $this->createCustomer($this->shop->id);
        ShopPreferences::withoutTenant()->updateOrCreate(['shop_id' => $this->shop->id], [
            'refund_making_charges' => true, 'refund_stone_charges' => true, 'refund_gst' => true,
            'wear_loss_pct' => 0, 'restocking_fee_pct' => 0, 'return_settlement_mode' => 'cash_or_credit',
        ]);
    }

    private function at(string $ist): void
    {
        $this->travelTo(CarbonImmutable::parse($ist, 'Asia/Kolkata'));
        $this->actingAs($this->owner);
    }

    private function bill(): Invoice
    {
        $item = $this->createItem($this->shop->id, null, ['selling_price' => 1000]);

        return TenantContext::runFor($this->shop->id, function () use ($item) {
            $draft = new Invoice();
            $draft->forceFill([
                'shop_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'gold_rate' => 7200,
                'subtotal' => 0, 'gst' => 0, 'gst_rate' => 3, 'wastage_charge' => 0, 'discount' => 0,
                'round_off' => 0, 'total' => 0, 'status' => Invoice::STATUS_DRAFT,
            ])->save();
            InvoiceItem::record([
                'invoice_id' => $draft->id, 'item_id' => $item->id, 'metal_type' => $item->metal_type,
                'weight' => (float) $item->gross_weight, 'rate' => 0, 'making_charges' => 0, 'stone_amount' => 0,
                'hallmark_charges' => 0, 'line_total' => 1000, 'gst_rate' => 3, 'gst_amount' => 30,
                'allocated_discount' => 0, 'allocated_round_off' => 0, 'allocated_loyalty_pts' => 0,
            ]);

            return InvoiceAccountingService::finalizeDraft($draft);
        });
    }

    private function revenue(string $from, string $to): float
    {
        return (float) app(ProfitReportingService::class)->summary(
            $this->shop->id,
            ReportPeriod::range($from, $to)
        )->revenue;
    }

    public function test_a_bill_cancelled_by_reversal_in_the_same_month_nets_to_zero(): void
    {
        $this->at('2026-05-10 12:00:00');
        $bill = $this->bill();
        TenantContext::runFor($this->shop->id, fn () => InvoiceAccountingService::cancelByReversal($bill->fresh(), 'wrong customer entered'));

        $this->assertEqualsWithDelta(0.0, $this->revenue('2026-05-01', '2026-05-31'), 0.01);
    }

    public function test_a_full_return_in_the_same_month_nets_to_zero(): void
    {
        $this->at('2026-05-10 12:00:00');
        $bill = $this->bill();
        TenantContext::runFor($this->shop->id, fn () => app(ReturnService::class)->createFullReturn(
            $bill->fresh(), 'customer changed mind', $this->owner->id, false, ReturnService::REFUND_SETTLEMENT_STORE_CREDIT));

        $this->assertEqualsWithDelta(0.0, $this->revenue('2026-05-01', '2026-05-31'), 0.01);
    }

    public function test_a_march_sale_returned_in_may_stays_in_march_and_the_return_lands_in_may(): void
    {
        $this->at('2026-03-20 12:00:00');
        $bill = $this->bill();
        $marchBefore = $this->revenue('2026-03-01', '2026-03-31');

        $this->at('2026-05-10 12:00:00');
        TenantContext::runFor($this->shop->id, fn () => app(ReturnService::class)->createFullReturn(
            $bill->fresh(), 'customer returned it', $this->owner->id, false, ReturnService::REFUND_SETTLEMENT_STORE_CREDIT));

        $this->assertEqualsWithDelta($marchBefore, $this->revenue('2026-03-01', '2026-03-31'), 0.01, 'a closed month must not change');
        $this->assertEqualsWithDelta(-1000.0, $this->revenue('2026-05-01', '2026-05-31'), 0.01, 'the return reduces May');
    }

    public function test_a_march_sale_cancelled_in_may_stays_in_march(): void
    {
        $this->at('2026-03-20 12:00:00');
        $bill = $this->bill();
        $marchBefore = $this->revenue('2026-03-01', '2026-03-31');

        $this->at('2026-05-10 12:00:00');
        TenantContext::runFor($this->shop->id, fn () => InvoiceAccountingService::cancelByReversal($bill->fresh(), 'cancelled after month end'));

        $this->assertEqualsWithDelta($marchBefore, $this->revenue('2026-03-01', '2026-03-31'), 0.01);
        $this->assertEqualsWithDelta(-1000.0, $this->revenue('2026-05-01', '2026-05-31'), 0.01);
    }
}

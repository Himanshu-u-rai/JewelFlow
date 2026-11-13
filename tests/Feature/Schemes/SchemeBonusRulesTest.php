<?php

namespace Tests\Feature\Schemes;

use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Models\Shop;
use App\Models\User;
use App\Services\InvoiceAccountingService;
use App\Services\SchemeService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit H3 (2026-11-13). Two holes let a gold-savings customer collect the maturity bonus without earning it:
 *
 * 1. Spending everything paid so far on a bill flipped an ACTIVE enrollment to "redeemed", and "redeemed" counted as
 *    "bonus earned": the full bonus became redeemable after a few months, and no further instalment was accepted.
 * 2. A contribution could be any amount (from ₹1) and each payment counted as one instalment: eleven ₹1 payments
 *    on a ₹1,000-a-month, 11-month scheme matured it with the full bonus. A plan matured by date while under-paid
 *    "earned no bonus" by its flag, but the matured status alone still made the bonus redeemable.
 *
 * Owner's rule: the bonus is earned only by paying every instalment (money, not payment count), and only the
 * "bonus accrued" flag makes it redeemable.
 */
class SchemeBonusRulesTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{User, Shop, SchemeEnrollment} ₹1,000 a month, 11 months, ₹1,000 bonus */
    private function enrollment(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $scheme = new Scheme();
        $scheme->forceFill([
            'shop_id' => $shop->id, 'name' => 'Gold 11+1', 'type' => 'gold_savings', 'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(), 'total_installments' => 11, 'bonus_month_value' => 1000,
        ])->save();
        $this->actingAs($user);
        $enrollment = TenantContext::runFor($shop->id, fn () => app(SchemeService::class)->enroll($scheme, $customer, 1000, null, true));

        return [$user, $shop, $enrollment];
    }

    private function pay(Shop $shop, SchemeEnrollment $e, float $amount): SchemeEnrollment
    {
        TenantContext::runFor($shop->id, fn () => app(SchemeService::class)->recordPayment($e->fresh(), $amount));

        return $e->fresh();
    }

    /** Spend from the scheme on a fresh bill of $billTotal; returns what was applied. */
    private function redeem(Shop $shop, SchemeEnrollment $e, float $amount, float $billTotal = 50000): float
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $e, $amount, $billTotal) {
            $invoice = InvoiceAccountingService::createFinalized([
                'shop_id' => $shop->id, 'customer_id' => $e->customer_id, 'gold_rate' => 0, 'subtotal' => $billTotal,
                'gst' => 0, 'gst_rate' => 0, 'wastage_charge' => 0, 'total' => $billTotal, 'round_off' => 0,
            ]);

            return (float) app(SchemeService::class)->applyRedemptionToInvoice($e->fresh(), $invoice, $amount)->amount;
        });
    }

    public function test_spending_what_was_paid_early_keeps_the_scheme_running_and_earns_no_bonus(): void
    {
        [, $shop, $e] = $this->enrollment();
        foreach ([1, 2, 3] as $_) {
            $e = $this->pay($shop, $e, 1000);
        }

        $this->assertEqualsWithDelta(3000, $this->redeem($shop, $e, 3000), 0.01);
        $e = $e->fresh();

        $this->assertSame('active', $e->status, 'three of eleven months paid: the scheme is still running');
        $this->assertEqualsWithDelta(0, $e->redeemableAmount(), 0.01, 'no bonus before maturity');
        $this->assertSame(0.0, TenantContext::runFor($shop->id, fn () => app(SchemeService::class)->redeemableValue($e)));

        $e = $this->pay($shop, $e, 1000);
        $this->assertSame(4, $e->installments_paid, 'the customer can keep paying');
    }

    public function test_a_redemption_cannot_spend_an_unearned_bonus(): void
    {
        [, $shop, $e] = $this->enrollment();
        $e = $this->pay($shop, $e, 1000);
        $this->redeem($shop, $e, 1000);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No redeemable amount');
        $this->redeem($shop, $e->fresh(), 1000);
    }

    public function test_tiny_payments_do_not_count_as_instalments_or_mature_the_scheme(): void
    {
        [, $shop, $e] = $this->enrollment();
        for ($i = 0; $i < 11; $i++) {
            $e = $this->pay($shop, $e, 1);
        }

        $this->assertSame('active', $e->status);
        $this->assertSame(0, $e->installments_paid, '₹11 covers no ₹1,000 instalment');
        $this->assertFalse($e->is_bonus_accrued);
        $this->assertEqualsWithDelta(11, $e->redeemableAmount(), 0.01);
    }

    public function test_paying_every_instalment_earns_the_bonus(): void
    {
        [, $shop, $e] = $this->enrollment();
        $e = $this->pay($shop, $e, 2000);
        $this->assertSame(2, $e->installments_paid, 'one payment covering two months counts two');
        $e = $this->pay($shop, $e, 9000);

        $this->assertSame('matured', $e->status);
        $this->assertSame(11, $e->installments_paid);
        $this->assertTrue($e->is_bonus_accrued);
        $this->assertEqualsWithDelta(12000, $e->redeemableAmount(), 0.01, '11 months paid + 1 month bonus');

        $this->assertEqualsWithDelta(12000, $this->redeem($shop, $e, 12000), 0.01);
        $this->assertSame('redeemed', $e->fresh()->status, 'a matured scheme spent in full is redeemed');
    }

    public function test_more_than_the_remaining_contributions_is_refused(): void
    {
        [, $shop, $e] = $this->enrollment();
        $e = $this->pay($shop, $e, 10000);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('more than the remaining contributions');
        $this->pay($shop, $e, 1001);
    }

    public function test_a_plan_matured_by_date_while_under_paid_has_no_redeemable_bonus(): void
    {
        [, $shop, $e] = $this->enrollment();
        $e = $this->pay($shop, $e, 5000);
        $e->forceFill(['maturity_date' => now()->subDay()->toDateString()])->save();

        TenantContext::runFor($shop->id, fn () => app(SchemeService::class)->matureEnrollment($e->fresh()));
        $e = $e->fresh();

        $this->assertSame('matured', $e->status);
        $this->assertFalse($e->is_bonus_accrued);
        $this->assertEqualsWithDelta(5000, $e->redeemableAmount(), 0.01, 'what was paid, without the bonus');
    }
}

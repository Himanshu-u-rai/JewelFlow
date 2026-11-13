<?php

namespace Tests\Feature\Installments;

use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\Shop;
use App\Models\User;
use App\Services\InstallmentService;
use App\Services\RetailerSalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit H2 (2026-11-13): "EMIs paid" went up by one for every payment whatever its size, and the plan was closed
 * (balance forced to 0) when that count reached the number of EMIs. Twelve half payments on a 12-EMI plan closed it
 * with half the money unpaid, silently written off, while the bill still showed the due. One payment covering two
 * months counted as one, and every payment set the next due date to "a month from today".
 *
 * Owner's rule: a plan is completed only when its money is paid. EMIs paid = how many instalments the money paid
 * covers; the next due date is the schedule's date for the first uncovered instalment.
 */
class EmiPlanCompletionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{User, Shop, InstallmentPlan} a 12-EMI plan, no interest, no down payment */
    private function plan(int $emis = 12): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $this->createMetalLot($shop->id)->id);
        Carbon::setTestNow('2026-01-15 10:00:00');
        $plan = TenantContext::runFor($shop->id, function () use ($user, $customer, $item, $emis) {
            $this->actingAs($user);
            $draft = RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$item->id]);

            return app(InstallmentService::class)->finalizeDraftInvoiceToPlan($draft, 0, $emis, 0);
        });

        return [$user, $shop, $plan->fresh()];
    }

    private function pay(Shop $shop, InstallmentPlan $plan, float $amount): InstallmentPlan
    {
        TenantContext::runFor($shop->id, fn () => app(InstallmentService::class)->recordPayment($plan->fresh(), $amount));

        return $plan->fresh();
    }

    private function invoiceDue(Shop $shop, InstallmentPlan $plan): float
    {
        return TenantContext::runFor($shop->id, function () use ($plan) {
            $invoice = Invoice::with('payments')->findOrFail($plan->invoice_id);

            return round((float) $invoice->total - (float) $invoice->payments->sum('amount'), 2);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_twelve_half_payments_do_not_close_a_twelve_emi_plan(): void
    {
        [, $shop, $plan] = $this->plan();
        $half = round((float) $plan->emi_amount / 2, 2);

        for ($i = 0; $i < 12; $i++) {
            $plan = $this->pay($shop, $plan, $half);
        }

        $this->assertSame('active', $plan->status, 'half the money is still owed');
        $this->assertEqualsWithDelta((float) $plan->total_payable - 12 * $half, (float) $plan->remaining_amount, 0.01);
        $this->assertSame(6, $plan->emis_paid, 'twelve half payments cover six instalments');
        $this->assertEqualsWithDelta((float) $plan->remaining_amount, $this->invoiceDue($shop, $plan), 0.01, 'plan and bill agree');
    }

    public function test_one_payment_covering_two_emis_counts_two_and_moves_the_due_date_two_months(): void
    {
        [, $shop, $plan] = $this->plan();
        $plan = $this->pay($shop, $plan, 2 * (float) $plan->emi_amount);

        $this->assertSame(2, $plan->emis_paid);
        $this->assertSame('2026-04-15', $plan->next_due_date->toDateString(), 'instalment 3 falls three months after the sale');
    }

    public function test_a_part_payment_leaves_the_due_date_on_the_unpaid_instalment(): void
    {
        [, $shop, $plan] = $this->plan();
        Carbon::setTestNow('2026-02-25 10:00:00'); // paid late
        $plan = $this->pay($shop, $plan, (float) $plan->emi_amount - 1);

        $this->assertSame(0, $plan->emis_paid);
        $this->assertSame('2026-02-15', $plan->next_due_date->toDateString(), 'instalment 1 is still due, still overdue');

        $plan = $this->pay($shop, $plan, 1);
        $this->assertSame(1, $plan->emis_paid);
        $this->assertSame('2026-03-15', $plan->next_due_date->toDateString(), 'a late payment does not shift the schedule');
    }

    /** Break attempt: a plan begun on the 31st keeps one schedule from the first due date on (no Mar 3 then Mar 31). */
    public function test_a_plan_begun_at_month_end_keeps_one_schedule(): void
    {
        Carbon::setTestNow('2026-01-31 10:00:00');
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $this->createMetalLot($shop->id)->id);
        $plan = TenantContext::runFor($shop->id, function () use ($user, $customer, $item) {
            $this->actingAs($user);
            $draft = RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$item->id]);

            return app(InstallmentService::class)->finalizeDraftInvoiceToPlan($draft, 0, 6, 0);
        })->fresh();

        $this->assertSame('2026-02-28', $plan->next_due_date->toDateString(), 'first instalment: end of February');
        $plan = $this->pay($shop, $plan, (float) $plan->emi_amount);
        $this->assertSame('2026-03-31', $plan->next_due_date->toDateString(), 'second instalment: end of March');
    }

    /** Break attempt: more than the balance, or a plan closed a moment ago, is an answer on the page, not a 500. */
    public function test_the_web_refuses_an_overpayment_and_a_closed_plan_cleanly(): void
    {
        [$user, $shop, $plan] = $this->plan(3);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->post(route('installments.pay', $plan), [
            'amount' => (float) $plan->total_payable + 100, 'payment_method' => 'cash',
        ]));
        $res->assertRedirect()->assertSessionHasErrors('amount');
        $this->assertSame(0, $plan->fresh()->emis_paid);

        // Closed after the page loaded the plan: the service's locked check answers.
        $stale = $plan->fresh();
        $this->pay($shop, $plan, (float) $plan->total_payable);
        $this->app->instance(InstallmentPlan::class, $stale);
        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->post(route('installments.pay', $plan), [
            'amount' => 10, 'payment_method' => 'cash',
        ]));
        $this->assertLessThan(500, $res->getStatusCode());
    }

    public function test_the_plan_completes_when_its_money_is_paid(): void
    {
        [, $shop, $plan] = $this->plan(3);
        $plan = $this->pay($shop, $plan, (float) $plan->total_payable - 0.5);
        $this->assertSame('active', $plan->status);
        $this->assertSame(2, $plan->emis_paid, 'the last instalment is not covered yet');

        $plan = $this->pay($shop, $plan, 0.5);
        $this->assertSame('completed', $plan->status);
        $this->assertSame(3, $plan->emis_paid);
        $this->assertEqualsWithDelta(0, (float) $plan->remaining_amount, 0.001);
        $this->assertNull($plan->next_due_date);
        $this->assertEqualsWithDelta(0, $this->invoiceDue($shop, $plan), 0.01);
    }
}

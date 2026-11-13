<?php

namespace Tests\Feature\Installments;

use App\Models\AuditLog;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\Shop;
use App\Models\User;
use App\Services\InstallmentService;
use App\Services\RetailerSalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Plans the old EMI rule closed early (audit H2, fixed going forward in 7b0842f5): the count of payments reached the
 * EMI count, the plan was marked completed and its balance forced to 0, while the customer still owed money and the
 * bill still showed the due.
 *
 * Repair follows CONSTITUTION §2 F5 / §3 Lane 2: `emi:early-closed` only reports (exit 1 when it finds any);
 * `emi:reopen-early-closed` is run by a person for named plans with a reason, through an audited service method. No
 * money moves: the plan gets back its true balance, the instalments that money covers, and its schedule's due date.
 */
class EmiEarlyClosedRepairTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A 6-EMI plan closed by the old rule after six half payments. @return array{User, Shop, InstallmentPlan} */
    private function earlyClosed(): array
    {
        Carbon::setTestNow('2026-01-10 10:00:00');
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $this->createMetalLot($shop->id)->id);
        $plan = TenantContext::runFor($shop->id, function () use ($user, $customer, $item) {
            $this->actingAs($user);
            $draft = RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$item->id]);

            return app(InstallmentService::class)->finalizeDraftInvoiceToPlan($draft, 0, 6, 0);
        })->fresh();

        $half = round((float) $plan->emi_amount / 2, 2);
        for ($i = 0; $i < 6; $i++) {
            TenantContext::runFor($shop->id, fn () => app(InstallmentService::class)->recordPayment($plan->fresh(), $half));
        }
        // What the old rule then wrote: closed, balance forced to 0.
        DB::table('installment_plans')->where('id', $plan->id)
            ->update(['status' => 'completed', 'remaining_amount' => 0, 'emis_paid' => 6, 'next_due_date' => null]);

        return [$user, $shop, $plan->fresh()];
    }

    private function fullyPaid(Shop $shop, User $user): InstallmentPlan
    {
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $this->createMetalLot($shop->id)->id);
        $plan = TenantContext::runFor($shop->id, function () use ($user, $customer, $item) {
            $this->actingAs($user);
            $draft = RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$item->id]);

            return app(InstallmentService::class)->finalizeDraftInvoiceToPlan($draft, 0, 2, 0);
        });
        TenantContext::runFor($shop->id, fn () => app(InstallmentService::class)->recordPayment($plan->fresh(), (float) $plan->total_payable));

        return $plan->fresh();
    }

    public function test_the_check_lists_early_closed_plans_and_changes_nothing(): void
    {
        [$user, $shop, $plan] = $this->earlyClosed();
        $paid = $this->fullyPaid($shop, $user);

        $this->artisan('emi:early-closed')
            ->expectsOutputToContain("plan #{$plan->id}")
            ->assertExitCode(1);

        $this->assertSame('completed', $plan->fresh()->status, 'the check is read-only');
        $this->assertEqualsWithDelta(0, (float) $plan->fresh()->remaining_amount, 0.001);
        $this->assertSame('completed', $paid->fresh()->status);
    }

    public function test_a_clean_shop_passes_the_check(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->fullyPaid($shop, $user);

        $this->artisan('emi:early-closed')->expectsOutputToContain('No EMI plan')->assertExitCode(0);
    }

    public function test_reopening_restores_the_true_balance_schedule_and_audit(): void
    {
        [$user, $shop, $plan] = $this->earlyClosed();
        Carbon::setTestNow('2026-08-01 10:00:00');
        $owed = round((float) $plan->total_payable - (float) DB::table('installment_payments')->where('plan_id', $plan->id)->sum('amount'), 2);

        $this->artisan('emi:reopen-early-closed', ['plan' => [$plan->id], '--reason' => 'Closed early by the old EMI rule'])
            ->assertExitCode(0);

        $plan = $plan->fresh();
        $this->assertSame('active', $plan->status);
        $this->assertEqualsWithDelta($owed, (float) $plan->remaining_amount, 0.01);
        $this->assertSame(3, $plan->emis_paid, 'six half payments cover three instalments');
        $this->assertSame('2026-05-10', $plan->next_due_date->toDateString(), 'instalment 4, still overdue');

        $invoice = TenantContext::runFor($shop->id, fn () => Invoice::with('payments')->findOrFail($plan->invoice_id));
        $this->assertEqualsWithDelta($owed, (float) $invoice->total - (float) $invoice->payments->sum('amount'), 0.01, 'plan and bill agree again');

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'emi_plan_reopened')->where('model_id', $plan->id)->sole();
        $this->assertSame('Closed early by the old EMI rule', $audit->data['reason']);
        $this->assertEqualsWithDelta($owed, (float) $audit->data['after']['remaining_amount'], 0.01);

        // The customer can pay what they owe.
        TenantContext::runFor($shop->id, fn () => app(InstallmentService::class)->recordPayment($plan->fresh(), $owed));
        $this->assertSame('completed', $plan->fresh()->status);
    }

    public function test_reopening_refuses_a_plan_that_was_really_paid_and_needs_a_reason(): void
    {
        [$user, $shop, $plan] = $this->earlyClosed();
        $paid = $this->fullyPaid($shop, $user);

        $this->artisan('emi:reopen-early-closed', ['plan' => [$plan->id]])->assertExitCode(1);
        $this->assertSame('completed', $plan->fresh()->status, 'no reason, no change');

        $this->artisan('emi:reopen-early-closed', ['plan' => [$paid->id], '--reason' => 'test'])->assertExitCode(1);
        $this->assertSame('completed', $paid->fresh()->status, 'a paid plan is not reopened');

        $this->artisan('emi:reopen-early-closed', ['plan' => [$plan->id], '--reason' => 'test'])->assertExitCode(0);
        $this->artisan('emi:reopen-early-closed', ['plan' => [$plan->id], '--reason' => 'again'])->assertExitCode(1);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'emi_plan_reopened')->count(), 'reopened once');
    }
}

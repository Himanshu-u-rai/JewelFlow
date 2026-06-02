<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Services\InstallmentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M2 (audit D1): an EMI plan had no terminal exit other than full
 * payment — a customer who stopped paying left the plan permanently 'active'
 * + overdue, with no settle/write-off/close action. The DB already allowed the
 * 'defaulted' state and the index already counted it; only the transition was
 * missing.
 *
 * markDefaulted() is an OPERATIONAL close (write-off), not an accounting
 * reversal: the finalized invoice and recorded payments are left untouched; the
 * uncollected remainder is preserved on the row + captured in the audit trail.
 */
class InstallmentDefaultCloseTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeActivePlan(int $shopId, float $remaining = 5000.0): InstallmentPlan
    {
        $customer = $this->createCustomer($shopId);

        $invoice = new Invoice();
        $invoice->forceFill([
            'shop_id'        => $shopId,
            'invoice_number' => 'INV-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'gold_rate'      => 6000,
            'subtotal'       => 10000,
            'gst'            => 300,
            'total'          => 10300,
            'status'         => 'finalized',
        ])->save();

        $plan = new InstallmentPlan();
        $plan->forceFill([
            'shop_id'          => $shopId,
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'total_amount'     => 10300,
            'down_payment'     => 2000,
            'remaining_amount' => $remaining,
            'emi_amount'       => 1000,
            'total_emis'       => 8,
            'emis_paid'        => 3,
            'next_due_date'    => now()->subDays(40)->toDateString(),
            'status'           => 'active',
        ])->save();

        return $plan;
    }

    public function test_route_and_permission_gate_exist(): void
    {
        $this->assertTrue(Route::has('installments.default'));
        $route = Route::getRoutes()->getByName('installments.default');
        $this->assertContains('can:sales.void', $route->gatherMiddleware(),
            'write-off must be gated on sales.void (manager/owner level)');
    }

    public function test_mark_defaulted_closes_plan_and_preserves_writeoff(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $plan = $this->makeActivePlan($shop->id, 5000.0);

        // Tenant context mirrors the live HTTP request scope; in console the
        // BelongsToShop global scope otherwise resolves to a null tenant (G5).
        TenantContext::runFor($shop->id, fn () =>
            app(InstallmentService::class)->markDefaulted($plan, 'Customer unreachable', 1)
        );

        $fresh = InstallmentPlan::withoutGlobalScopes()->find($plan->id);
        $this->assertSame('defaulted', $fresh->status);
        $this->assertNull($fresh->next_due_date);
        $this->assertEquals(5000.0, (float) $fresh->remaining_amount,
            'written-off remainder is preserved as the historical record');

        // Invoice untouched (no accounting reversal).
        $invoice = Invoice::withoutGlobalScopes()->find($plan->invoice_id);
        $this->assertSame('finalized', $invoice->status);

        // Audit trail captured the write-off.
        $this->assertDatabaseHas('audit_logs', [
            'shop_id'    => $shop->id,
            'action'     => 'installment_plan_defaulted',
            'model_type' => 'installment_plan',
            'model_id'   => $plan->id,
        ]);
    }

    public function test_cannot_default_an_already_closed_plan(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $plan = $this->makeActivePlan($shop->id);
        $plan->update(['status' => 'completed']);

        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () =>
            app(InstallmentService::class)->markDefaulted($plan, null, 1)
        );
    }
}

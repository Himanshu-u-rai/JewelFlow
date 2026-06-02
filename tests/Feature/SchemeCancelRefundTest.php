<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Models\SchemeLedgerEntry;
use App\Services\SchemeService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M3 (audit D2): a gold-savings enrollment had no way to be
 * cancelled with the customer's contributions refunded — SchemeService::
 * cancelEnrollment existed but only flipped status and refunded nothing, and it
 * had no route/UI. The fix refunds the current ledger balance (contributions in
 * − redemptions out, EXCLUDING un-accrued maturity bonus) as cash OUT, records a
 * reversing 'cancellation_refund' debit that zeroes the scheme ledger, and is
 * reachable from the enrollment page gated on sales.void.
 */
class SchemeCancelRefundTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeEnrollmentWithContributions(int $shopId, float $paid = 3000.0): SchemeEnrollment
    {
        $customer = $this->createCustomer($shopId);

        $scheme = new Scheme();
        $scheme->forceFill([
            'shop_id'    => $shopId,
            'name'       => 'Test Gold Savings',
            'type'       => 'gold_savings',
            'start_date' => now()->subMonths(2)->toDateString(),
        ])->save();

        $enrollment = new SchemeEnrollment();
        $enrollment->forceFill([
            'shop_id'            => $shopId,
            'scheme_id'          => $scheme->id,
            'customer_id'        => $customer->id,
            'start_date'         => now()->subMonths(2)->toDateString(),
            'monthly_amount'     => 1000,
            'total_paid'         => $paid,
            'installments_paid'  => 3,
            'total_installments' => 11,
            'bonus_amount'       => 1000, // must NOT be refunded
            'status'             => 'active',
        ])->save();

        // Ledger pot reflecting the contributions paid in.
        SchemeLedgerEntry::record([
            'shop_id'              => $shopId,
            'scheme_enrollment_id' => $enrollment->id,
            'entry_type'           => 'contribution',
            'direction'            => 'credit',
            'amount'               => $paid,
            'balance_after'        => $paid,
        ]);

        return $enrollment;
    }

    public function test_route_and_permission_gate_exist(): void
    {
        $this->assertTrue(Route::has('schemes.enrollment.cancel'));
        $route = Route::getRoutes()->getByName('schemes.enrollment.cancel');
        $this->assertContains('can:sales.void', $route->gatherMiddleware());
    }

    public function test_cancel_refunds_contributions_excluding_bonus(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $enrollment = $this->makeEnrollmentWithContributions($shop->id, 3000.0);

        TenantContext::runFor($shop->id, fn () =>
            app(SchemeService::class)->cancelEnrollment($enrollment, 'Customer relocated')
        );

        $fresh = SchemeEnrollment::withoutGlobalScopes()->find($enrollment->id);
        $this->assertSame('cancelled', $fresh->status);

        // Cash refund OUT for exactly the contributions (bonus excluded).
        $refund = CashTransaction::withoutGlobalScopes()
            ->where('source_type', 'scheme_refund')
            ->where('source_id', $enrollment->id)
            ->first();
        $this->assertNotNull($refund, 'a cash-out refund must be recorded');
        $this->assertSame('out', $refund->type);
        $this->assertEquals(3000.0, (float) $refund->amount, 'refund excludes the 1000 maturity bonus');

        // Reversing ledger debit zeroes the pot.
        $lastEntry = SchemeLedgerEntry::withoutGlobalScopes()
            ->where('scheme_enrollment_id', $enrollment->id)
            ->orderByDesc('id')->first();
        $this->assertSame('cancellation_refund', $lastEntry->entry_type);
        $this->assertSame('debit', $lastEntry->direction);
        $this->assertEquals(0.0, (float) $lastEntry->balance_after);

        $this->assertDatabaseHas('audit_logs', [
            'shop_id'    => $shop->id,
            'action'     => 'scheme_enrollment_cancelled',
            'model_type' => 'scheme_enrollment',
            'model_id'   => $enrollment->id,
        ]);
    }

    public function test_cannot_cancel_a_matured_enrollment(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $enrollment = $this->makeEnrollmentWithContributions($shop->id);
        $enrollment->update(['status' => 'matured']);

        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () =>
            app(SchemeService::class)->cancelEnrollment($enrollment, null)
        );
    }
}

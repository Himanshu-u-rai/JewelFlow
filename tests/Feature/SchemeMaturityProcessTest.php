<?php

namespace Tests\Feature;

use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Models\SchemeLedgerEntry;
use App\Services\SchemeService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M4 (audit D3): scheme maturity only fired on installment COUNT.
 * An enrollment that reached its maturity_date under-paid stayed 'active' forever
 * and never matured/redeemed. The daily schemes:process-maturity command now
 * matures by date — accruing the bonus ONLY when fully paid (the same rule the
 * payment path already enforces), maturing under-paid plans WITHOUT bonus so the
 * paid amount becomes redeemable.
 */
class SchemeMaturityProcessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeEnrollment(int $shopId, int $installmentsPaid, int $total = 11, string $maturity = '-1 day'): SchemeEnrollment
    {
        $customer = $this->createCustomer($shopId);
        $scheme = new Scheme();
        $scheme->forceFill([
            'shop_id' => $shopId, 'name' => 'GHS', 'type' => 'gold_savings',
            'start_date' => now()->subYear()->toDateString(),
        ])->save();

        $enrollment = new SchemeEnrollment();
        $enrollment->forceFill([
            'shop_id'            => $shopId,
            'scheme_id'          => $scheme->id,
            'customer_id'        => $customer->id,
            'start_date'         => now()->subYear()->toDateString(),
            'monthly_amount'     => 1000,
            'total_paid'         => 1000 * $installmentsPaid,
            'installments_paid'  => $installmentsPaid,
            'total_installments' => $total,
            'maturity_date'      => now()->modify($maturity)->toDateString(),
            'bonus_amount'       => 1000,
            'is_bonus_accrued'   => false,
            'status'             => 'active',
        ])->save();

        if ($installmentsPaid > 0) {
            SchemeLedgerEntry::record([
                'shop_id' => $shopId, 'scheme_enrollment_id' => $enrollment->id,
                'entry_type' => 'contribution', 'direction' => 'credit',
                'amount' => 1000 * $installmentsPaid, 'balance_after' => 1000 * $installmentsPaid,
            ]);
        }

        return $enrollment;
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('schemes:process-maturity --shop=999999')->assertExitCode(0);
    }

    public function test_fully_paid_matures_with_bonus(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $e = $this->makeEnrollment($shop->id, installmentsPaid: 11, total: 11);

        TenantContext::runFor($shop->id, fn () => app(SchemeService::class)->processMaturedEnrollments($shop->id));

        $fresh = SchemeEnrollment::withoutGlobalScopes()->find($e->id);
        $this->assertSame('matured', $fresh->status);
        $this->assertTrue((bool) $fresh->is_bonus_accrued, 'fully-paid plan accrues the bonus');
        $this->assertTrue(SchemeLedgerEntry::withoutGlobalScopes()
            ->where('scheme_enrollment_id', $e->id)->where('entry_type', 'bonus_accrual')->exists());
    }

    public function test_underpaid_matures_without_bonus(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $e = $this->makeEnrollment($shop->id, installmentsPaid: 4, total: 11);

        TenantContext::runFor($shop->id, fn () => app(SchemeService::class)->processMaturedEnrollments($shop->id));

        $fresh = SchemeEnrollment::withoutGlobalScopes()->find($e->id);
        $this->assertSame('matured', $fresh->status, 'term reached → matured even if under-paid');
        $this->assertFalse((bool) $fresh->is_bonus_accrued, 'under-paid plan earns NO bonus');
        $this->assertFalse(SchemeLedgerEntry::withoutGlobalScopes()
            ->where('scheme_enrollment_id', $e->id)->where('entry_type', 'bonus_accrual')->exists());
    }

    public function test_not_yet_matured_is_left_active(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $e = $this->makeEnrollment($shop->id, installmentsPaid: 3, total: 11, maturity: '+2 months');

        $count = TenantContext::runFor($shop->id, fn () => app(SchemeService::class)->processMaturedEnrollments($shop->id));

        $this->assertSame(0, $count);
        $this->assertSame('active', SchemeEnrollment::withoutGlobalScopes()->find($e->id)->status);
    }

    public function test_idempotent_no_double_bonus(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $e = $this->makeEnrollment($shop->id, installmentsPaid: 11, total: 11);

        TenantContext::runFor($shop->id, function () use ($shop) {
            app(SchemeService::class)->processMaturedEnrollments($shop->id);
            app(SchemeService::class)->processMaturedEnrollments($shop->id); // re-run
        });

        $this->assertSame(1, SchemeLedgerEntry::withoutGlobalScopes()
            ->where('scheme_enrollment_id', $e->id)->where('entry_type', 'bonus_accrual')->count(),
            'a second run must not double-accrue the bonus');
    }
}

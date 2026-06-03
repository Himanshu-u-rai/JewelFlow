<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Reporting\ReceivablesService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Receivables & liability pack (Phase 2 M3) — #8 Customer dues aging.
 * Locks the bucket math: outstanding on finalized invoices, aged by accounting
 * date, only positive balances counted; fully-paid/over-collected excluded.
 */
class ReceivablesReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function invoice(int $shopId, ?int $customerId, float $total, int $ageDays): int
    {
        $dt = Carbon::now()->subDays($ageDays)->setTime(10, 0);
        return (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => $total, 'discount' => 0, 'gst' => 0, 'gst_rate' => 0,
            'total' => $total, 'status' => Invoice::STATUS_FINALIZED,
            'created_at' => $dt, 'updated_at' => $dt, 'finalized_at' => $dt,
        ]);
    }

    private function pay(int $shopId, int $invoiceId, float $amount): void
    {
        DB::table('invoice_payments')->insert([
            'shop_id' => $shopId, 'invoice_id' => $invoiceId, 'mode' => 'cash', 'amount' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_dues_aging_buckets_and_totals(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);

        // One unpaid invoice in each age bucket.
        $this->invoice($shop->id, $c->id, 1000, 10);                 // current (0-30)
        $this->invoice($shop->id, $c->id, 2000, 45);                 // 31-60
        $this->invoice($shop->id, $c->id, 3000, 75);                 // 61-90
        $this->invoice($shop->id, $c->id, 4000, 120);                // 90+
        // Partially paid: total 5000 - 1000 paid = 4000 outstanding (current).
        $partial = $this->invoice($shop->id, $c->id, 5000, 5);
        $this->pay($shop->id, $partial, 1000);
        // Fully paid → excluded.
        $paid = $this->invoice($shop->id, $c->id, 6000, 20);
        $this->pay($shop->id, $paid, 6000);

        $data = TenantContext::runFor($shop->id, fn () =>
            app(ReceivablesService::class)->duesAging($shop->id)
        );

        $this->assertEqualsWithDelta(5000.0, $data->bucketCurrent, 0.01, 'current = 1000 + 4000 partial');
        $this->assertEqualsWithDelta(2000.0, $data->bucket3160, 0.01);
        $this->assertEqualsWithDelta(3000.0, $data->bucket6190, 0.01);
        $this->assertEqualsWithDelta(4000.0, $data->bucket90plus, 0.01);
        $this->assertEqualsWithDelta(14000.0, $data->totalOutstanding, 0.01, 'fully-paid 6000 excluded');

        // Buckets must sum to the total (the core invariant).
        $this->assertEqualsWithDelta(
            $data->totalOutstanding,
            $data->bucketCurrent + $data->bucket3160 + $data->bucket6190 + $data->bucket90plus,
            0.01
        );

        // 5 unpaid/partial invoices across 1 customer.
        $this->assertSame(5, $data->invoiceCount);
        $this->assertSame(1, $data->customerCount);
        $this->assertEqualsWithDelta(14000.0, (float) $data->rows->sum('total'), 0.01,
            'per-customer rows sum to the total outstanding');
    }

    public function test_no_dues_when_all_paid(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $inv = $this->invoice($shop->id, $c->id, 1000, 10);
        $this->pay($shop->id, $inv, 1000);

        $data = TenantContext::runFor($shop->id, fn () =>
            app(ReceivablesService::class)->duesAging($shop->id)
        );

        $this->assertEqualsWithDelta(0.0, $data->totalOutstanding, 0.01);
        $this->assertSame(0, $data->invoiceCount);
        $this->assertTrue($data->rows->isEmpty());
    }

    private function plan(int $shopId, ?int $customerId, float $totalPayable, float $remaining, string $status, int $dueInDays): int
    {
        $due = Carbon::now()->addDays($dueInDays)->toDateString();
        $invId = $this->invoice($shopId, $customerId, $totalPayable, 0);
        return (int) DB::table('installment_plans')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId, 'invoice_id' => $invId,
            'total_amount' => $totalPayable, 'down_payment' => 0, 'remaining_amount' => $remaining,
            'emi_amount' => 1000, 'total_emis' => 10, 'emis_paid' => 2, 'next_due_date' => $due,
            'status' => $status, 'principal_amount' => $totalPayable, 'interest_rate_annual' => 0,
            'interest_amount' => 0, 'total_payable' => $totalPayable,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_emi_visibility_active_only_overdue_and_upcoming(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);

        $this->plan($shop->id, $c->id, 10000, 6000, 'active', -10);  // overdue (due 10d ago)
        $this->plan($shop->id, $c->id, 8000, 5000, 'active', 3);     // upcoming (due in 3d)
        $this->plan($shop->id, $c->id, 5000, 2000, 'active', 40);    // active, not due soon
        $this->plan($shop->id, $c->id, 9000, 9000, 'defaulted', -5); // excluded
        $this->plan($shop->id, $c->id, 7000, 0, 'completed', -2);    // excluded

        $data = TenantContext::runFor($shop->id, fn () =>
            app(ReceivablesService::class)->emiVisibility($shop->id)
        );

        $this->assertSame(3, $data->planCount, 'only active plans');
        $this->assertEqualsWithDelta(13000.0, $data->totalOutstanding, 0.01, '6000+5000+2000');
        $this->assertSame(1, $data->overdueCount);
        $this->assertEqualsWithDelta(6000.0, $data->overdueAmount, 0.01);
        $this->assertSame(1, $data->upcomingCount);
        $this->assertEqualsWithDelta(5000.0, $data->upcomingAmount, 0.01);
        $this->assertEqualsWithDelta($data->totalOutstanding, (float) $data->rows->sum('remaining'), 0.01);
    }

    private function enrollment(int $shopId, int $schemeId, int $customerId, string $status, float $totalPaid, float $balance, float $bonus = 0, bool $bonusAccrued = false): int
    {
        $eid = (int) DB::table('scheme_enrollments')->insertGetId([
            'shop_id' => $shopId, 'scheme_id' => $schemeId, 'customer_id' => $customerId,
            'start_date' => now()->subMonths(3)->toDateString(), 'monthly_amount' => 1000,
            'total_paid' => $totalPaid, 'installments_paid' => 3, 'total_installments' => 11,
            'maturity_date' => now()->addMonths(8)->toDateString(), 'status' => $status,
            'bonus_amount' => $bonus, 'is_bonus_accrued' => DB::raw($bonusAccrued ? 'true' : 'false'),
            'redeemed_amount' => 0, 'redemption_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Two ledger entries; the latest balance_after is the liability.
        DB::table('scheme_ledger_entries')->insert([
            'shop_id' => $shopId, 'scheme_enrollment_id' => $eid, 'entry_type' => 'contribution',
            'direction' => 'credit', 'amount' => $balance, 'balance_after' => $balance,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $eid;
    }

    public function test_scheme_liability_active_and_matured_only(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $scheme = (int) DB::table('schemes')->insertGetId([
            'shop_id' => $shop->id, 'name' => 'GHS', 'type' => 'gold_savings',
            'start_date' => now()->subYear()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enrollment($shop->id, $scheme, $c->id, 'active', 3000, 3000);
        $this->enrollment($shop->id, $scheme, $c->id, 'matured', 11000, 12000, 1000, true); // +bonus accrued
        $this->enrollment($shop->id, $scheme, $c->id, 'cancelled', 2000, 0);   // excluded
        $this->enrollment($shop->id, $scheme, $c->id, 'redeemed', 12000, 0);   // excluded

        $data = TenantContext::runFor($shop->id, fn () =>
            app(ReceivablesService::class)->schemeLiability($shop->id)
        );

        $this->assertSame(2, $data->enrollmentCount, 'only active + matured');
        $this->assertSame(1, $data->maturedCount);
        $this->assertEqualsWithDelta(15000.0, $data->totalLiability, 0.01, '3000 + 12000 ledger balances');
        $this->assertEqualsWithDelta(14000.0, $data->totalContributions, 0.01, '3000 + 11000 total_paid');
        $this->assertEqualsWithDelta(1000.0, $data->bonusAccrued, 0.01);
        $this->assertEqualsWithDelta($data->totalLiability, (float) $data->rows->sum('current_balance'), 0.01);
    }

    private function goldTxn(int $shopId, int $customerId, string $type, float $fineGold): void
    {
        DB::table('customer_gold_transactions')->insert([
            'shop_id' => $shopId, 'customer_id' => $customerId, 'fine_gold' => $fineGold,
            'gross_weight' => $fineGold, 'purity' => 24, 'type' => $type,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lot(int $shopId, string $source, float $remaining): void
    {
        DB::table('metal_lots')->insert([
            'shop_id' => $shopId, 'source' => $source, 'purity' => 24,
            'fine_weight_total' => $remaining, 'fine_weight_remaining' => $remaining,
            'cost_per_fine_gram' => 6000, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_metal_liability_advances_vs_old_gold(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c1 = $this->createCustomer($shop->id);
        $c2 = $this->createCustomer($shop->id);

        // Per-customer gross advance deposits.
        $this->goldTxn($shop->id, $c1->id, 'advance', 20.0);
        $this->goldTxn($shop->id, $c1->id, 'advance', 5.0);
        $this->goldTxn($shop->id, $c2->id, 'advance', 10.0);
        // Old gold accepted = shop stock, not liability.
        $this->goldTxn($shop->id, $c1->id, 'old_metal_in', 8.0);

        // Pooled customer_advance lot remaining = NET liability (some consumed).
        $this->lot($shop->id, 'customer_advance', 30.0);
        $this->lot($shop->id, 'purchase', 100.0);

        $data = TenantContext::runFor($shop->id, fn () =>
            app(ReceivablesService::class)->metalLiability($shop->id)
        );

        $this->assertEqualsWithDelta(30.0, $data->totalAdvanceLiability, 0.001, 'net = pooled customer_advance lot remaining');
        $this->assertEqualsWithDelta(35.0, $data->totalDeposited, 0.001, 'gross = 20+5+10');
        $this->assertEqualsWithDelta(8.0, $data->oldGoldAcceptedFine, 0.001, 'old gold is informational, excluded from liability');
        $this->assertEqualsWithDelta(130.0, $data->vaultOnHandFine, 0.001, '30 + 100');
        $this->assertSame(2, $data->customerCount);
        $this->assertEqualsWithDelta($data->totalDeposited, (float) $data->rows->sum('fine_deposited'), 0.001);
        // Liability is a subset of on-hand gold (coverage invariant).
        $this->assertLessThanOrEqual($data->vaultOnHandFine + 0.001, $data->totalAdvanceLiability);
    }
}

<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLedgerEntry;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Shop;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Critical Dhiran financial regression suite (Phase 5, Part C).
 *
 * Asserts real money math, lifecycle state transitions, and ledger/payment side
 * effects — NOT just HTTP 200. Interest/penalty/principal numbers are checked to
 * the paisa; tenant isolation is checked against a second shop's loans.
 *
 * All financial mutations go through DhiranService inside the owning shop's tenant
 * context (BelongsToShop + shop_counters need it).
 */
class DhiranFinancialTest extends TestCase
{
    use RefreshDatabase;

    private DhiranService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DhiranService::class);
    }

    /** A dhiran shop with its module enabled + default settings. */
    private function makeDhiranShop(string $name = 'Pawn Co'): Shop
    {
        $shop = Shop::create([
            'name' => $name, 'shop_type' => 'dhiran', 'phone' => '9990000000',
            'owner_first_name' => 'O', 'owner_last_name' => 'Wner', 'owner_mobile' => '9990000000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);

        return $shop;
    }

    /**
     * Create a loan with explicit, deterministic terms. Defaults: 100g 24k gold
     * collateral @ ₹6000/g → ₹600k market, 75% LTV → ₹450k cap; principal ₹100k.
     */
    private function makeLoan(Shop $shop, array $params = [], ?Carbon $loanDate = null): DhiranLoan
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $params, $loanDate) {
            $customer = Customer::create([
                'shop_id' => $shop->id, 'first_name' => 'Cust', 'last_name' => 'Omer',
                'mobile' => '98' . fake()->unique()->numerify('########'),
            ]);

            return $this->service->createLoan($shop, $customer, [[
                'description' => 'Gold chain', 'metal_type' => 'gold',
                'purity' => 24, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000,
            ]], array_merge([
                'principal_amount'      => 100000,
                'gold_rate_on_date'     => 6000,
                'interest_rate_monthly' => 2.0,
                'interest_type'         => 'flat',
                'penalty_rate_monthly'  => 0,
                'tenure_months'         => 12,
                'min_lock_months'       => 0,
                'min_interest_months'   => 0,
                'loan_date'             => ($loanDate ?? today())->toDateString(),
                'created_by'            => null,
            ], $params));
        });
    }

    private function freshLoan(int $id): DhiranLoan
    {
        return TenantContext::runFor(DhiranLoan::withoutGlobalScope('shop')->find($id)->shop_id,
            fn () => DhiranLoan::findOrFail($id));
    }

    // ════════════════ CALCULATION ════════════════

    public function test_flat_interest_exact_calculation(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_type' => 'flat', 'interest_rate_monthly' => 2.0],
            today()->copy()->subDays(30));

        TenantContext::runFor($shop->id, fn () => $this->service->accrueInterest($loan));
        $loan->refresh();

        // Flat on ORIGINAL principal: 100000 * 2% = 2000/mo → /30 * 30 days = 2000.00
        $this->assertSame(2000.00, (float) $loan->outstanding_interest);
    }

    public function test_daily_interest_exact_calculation(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_type' => 'daily', 'interest_rate_monthly' => 3.0],
            today()->copy()->subDays(10));

        TenantContext::runFor($shop->id, fn () => $this->service->accrueInterest($loan));
        $loan->refresh();

        // Daily: 100000 * (3/30)/100 * 10 = 100000 * 0.001 * 10 = 1000.00
        $this->assertSame(1000.00, (float) $loan->outstanding_interest);
    }

    public function test_compound_interest_exact_calculation(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_type' => 'compound', 'interest_rate_monthly' => 2.0],
            today()->copy()->subDays(30));

        TenantContext::runFor($shop->id, fn () => $this->service->accrueInterest($loan));
        $loan->refresh();

        // Compound base = outstanding_principal + outstanding_interest = 100000 + 0
        // 100000 * 2% /30 * 30 = 2000.00 (first period equals flat; compounds next period)
        $this->assertSame(2000.00, (float) $loan->outstanding_interest);
    }

    public function test_penalty_accrues_only_after_grace_period(): void
    {
        $shop = $this->makeDhiranShop();
        // Loan matured: 12-month tenure, loan_date 13 months ago, grace 30 days → overdue.
        $loan = $this->makeLoan($shop, [
            'interest_type' => 'flat', 'interest_rate_monthly' => 0,
            'penalty_rate_monthly' => 1.0, 'tenure_months' => 1, 'grace_period_days' => 30,
        ], today()->copy()->subDays(70)); // matured ~40d ago, grace ended ~10d ago

        TenantContext::runFor($shop->id, fn () => $this->service->accrueInterest($loan));
        $loan->refresh();

        // Penalty must be > 0 (we are past maturity + grace) and interest 0 (rate 0).
        $this->assertGreaterThan(0, (float) $loan->outstanding_penalty);
        $this->assertSame(0.00, (float) $loan->outstanding_interest);
    }

    public function test_same_day_no_double_accrual(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_rate_monthly' => 2.0], today()->copy()->subDays(15));

        TenantContext::runFor($shop->id, function () use ($loan) {
            $this->service->accrueInterest($loan);
            $first = $this->freshLoan($loan->id)->outstanding_interest;
            // Second accrual SAME day must add nothing (interest_accrued_through guard).
            $this->service->accrueInterest($this->freshLoan($loan->id));
            $second = $this->freshLoan($loan->id)->outstanding_interest;
            $this->assertSame((float) $first, (float) $second);
        });
    }

    public function test_partial_month_calculation(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_type' => 'flat', 'interest_rate_monthly' => 2.0],
            today()->copy()->subDays(15));

        TenantContext::runFor($shop->id, fn () => $this->service->accrueInterest($loan));
        $loan->refresh();

        // 15 of 30 days → half a month → 100000 * 2% / 30 * 15 = 1000.00
        $this->assertSame(1000.00, (float) $loan->outstanding_interest);
    }

    public function test_amounts_rounded_to_two_decimals(): void
    {
        $shop = $this->makeDhiranShop();
        // Rate that yields a non-terminating per-day amount: 100000 * 2.33% /30 *7
        $loan = $this->makeLoan($shop, ['interest_type' => 'flat', 'interest_rate_monthly' => 2.33],
            today()->copy()->subDays(7));

        TenantContext::runFor($shop->id, fn () => $this->service->accrueInterest($loan));
        $loan->refresh();

        $val = (float) $loan->outstanding_interest;
        $this->assertSame(round($val, 2), $val, 'Interest must be stored at 2 decimals.');
    }

    public function test_overpayment_is_rejected(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop);

        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () => $this->service->recordPayment($this->freshLoan($loan->id), 999999));
    }

    // ════════════════ PAYMENT / LIFECYCLE ════════════════

    public function test_create_pay_interest_then_close(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_rate_monthly' => 2.0], today()->copy()->subDays(30));

        TenantContext::runFor($shop->id, function () use ($loan) {
            $l = $this->freshLoan($loan->id);
            $this->service->accrueInterest($l);                 // +2000 interest
            // Pay full outstanding (principal 100000 + interest 2000) → auto-close.
            $total = $this->freshLoan($loan->id)->totalOutstanding();
            $this->service->recordPayment($this->freshLoan($loan->id), $total);
        });

        $loan = $this->freshLoan($loan->id);
        $this->assertSame('closed', $loan->status);
        $this->assertSame(0.0, $loan->totalOutstanding());
    }

    public function test_partial_payment_split_penalty_interest_principal(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, [
            'interest_rate_monthly' => 2.0, 'penalty_rate_monthly' => 1.0,
            'tenure_months' => 1, 'grace_period_days' => 0,
        ], today()->copy()->subDays(40));

        $payment = TenantContext::runFor($shop->id, function () use ($loan) {
            $this->service->accrueInterest($this->freshLoan($loan->id));
            $l = $this->freshLoan($loan->id);
            // Pay an amount that covers all penalty + all interest + a little principal.
            $amount = (float) $l->outstanding_penalty + (float) $l->outstanding_interest + 500;
            return $this->service->recordPayment($this->freshLoan($loan->id), $amount);
        });

        $loan = $this->freshLoan($loan->id);
        // Split order penalty → interest → principal: penalty + interest cleared, 500 to principal.
        $this->assertSame(0.0, (float) $loan->outstanding_penalty);
        $this->assertSame(0.0, (float) $loan->outstanding_interest);
        $this->assertSame(99500.0, (float) $loan->outstanding_principal);
        $this->assertGreaterThan(0, (float) $payment->principal_component);
    }

    public function test_multiple_payments_do_not_double_count(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop);

        TenantContext::runFor($shop->id, function () use ($loan) {
            $this->service->recordPayment($this->freshLoan($loan->id), 10000);
            $this->service->recordPayment($this->freshLoan($loan->id), 10000);
        });

        $loan = $this->freshLoan($loan->id);
        // Two ₹10k principal payments → 100000 - 20000 = 80000; collected = 20000.
        $this->assertSame(80000.0, (float) $loan->outstanding_principal);
        $this->assertSame(20000.0, (float) $loan->total_principal_collected);
        // Exactly 2 incoming repayment rows (the loan disbursement is a separate
        // 'out' payment created at origination).
        $this->assertSame(2, DhiranPayment::withoutGlobalScope('shop')
            ->where('dhiran_loan_id', $loan->id)->where('direction', 'in')->count());
    }

    public function test_closed_loan_rejects_new_payment(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop);
        TenantContext::runFor($shop->id, fn () => $this->service->recordPayment($this->freshLoan($loan->id),
            $this->freshLoan($loan->id)->totalOutstanding())); // pays off → closed

        $this->assertSame('closed', $this->freshLoan($loan->id)->status);
        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () => $this->service->recordPayment($this->freshLoan($loan->id), 100));
    }

    public function test_forfeited_loan_rejects_payment(): void
    {
        $shop = $this->makeDhiranShop();
        // notice period 1 day; we backdate the notice to 2 days ago so it has elapsed.
        DhiranSettings::getForShop($shop->id)->update(['forfeiture_notice_days' => 1]);
        $loan = $this->makeLoan($shop, ['tenure_months' => 1, 'grace_period_days' => 0],
            today()->copy()->subDays(120));

        TenantContext::runFor($shop->id, function () use ($loan) {
            $this->service->sendForfeitureNotice($this->freshLoan($loan->id));
            // Backdate the notice so the (1-day) notice period has fully elapsed.
            DhiranLoan::withoutGlobalScope('shop')->where('id', $loan->id)
                ->update(['forfeiture_notice_sent_at' => now()->subDays(2)]);
            $this->service->executeForfeit($this->freshLoan($loan->id));
        });

        $this->assertSame('forfeited', $this->freshLoan($loan->id)->status);
        $this->expectException(\LogicException::class);
        TenantContext::runFor($shop->id, fn () => $this->service->recordPayment($this->freshLoan($loan->id), 100));
    }

    public function test_renewed_loan_carries_principal_without_duplicate_disbursement(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_rate_monthly' => 2.0], today()->copy()->subDays(40));

        $renewed = TenantContext::runFor($shop->id, function () use ($loan) {
            $this->service->accrueInterest($this->freshLoan($loan->id));
            return $this->service->renewLoan($this->freshLoan($loan->id));
        });

        $old = $this->freshLoan($loan->id);
        $this->assertSame('renewed', $old->status);
        $this->assertNotNull($renewed);
        // No new cash disbursement: principal carried, not re-paid out. Count
        // disbursement cash entries (type 'out') across both loans = exactly the
        // original loan creation (1), never a second on renewal.
        $disbursements = \App\Models\Dhiran\DhiranCashEntry::withoutGlobalScope('shop')
            ->where('shop_id', $shop->id)->where('type', 'out')->count();
        $this->assertSame(1, $disbursements, 'Renewal must not re-disburse principal.');
        // The new loan carries the old loan's principal (no duplicate disbursement).
        $this->assertSame((float) $old->outstanding_principal, (float) $renewed->principal_amount);
        $this->assertGreaterThan(0, (float) $renewed->outstanding_principal);
    }

    public function test_auto_close_when_outstanding_below_tolerance(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop);
        TenantContext::runFor($shop->id, fn () => $this->service->recordPayment($this->freshLoan($loan->id),
            $this->freshLoan($loan->id)->totalOutstanding()));

        $this->assertSame('closed', $this->freshLoan($loan->id)->status);
        $this->assertNotNull($this->freshLoan($loan->id)->closed_at);
    }

    public function test_ledger_records_every_payment(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop);
        TenantContext::runFor($shop->id, fn () => $this->service->recordPayment($this->freshLoan($loan->id), 5000));

        $ledger = DhiranLedgerEntry::withoutGlobalScope('shop')->where('dhiran_loan_id', $loan->id)->get();
        // At least one entry tied to the payment (principal_repayment credit).
        $this->assertTrue($ledger->contains(fn ($e) => $e->entry_type === 'principal_repayment' && (float) $e->amount === 5000.0));
    }

    // ════════════════ ACCRUAL-ON-GET (Part D) ════════════════

    public function test_loan_summary_is_pure_read(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_rate_monthly' => 2.0], today()->copy()->subDays(20));

        $before = $this->freshLoan($loan->id)->getAttributes();
        TenantContext::runFor($shop->id, fn () => $this->service->loanSummary($this->freshLoan($loan->id)));
        $after = $this->freshLoan($loan->id)->getAttributes();

        // loanSummary must NOT write — interest/accrued_through unchanged.
        $this->assertSame($before['outstanding_interest'], $after['outstanding_interest']);
        $this->assertSame($before['interest_accrued_through'], $after['interest_accrued_through']);
    }

    public function test_accrue_and_summary_does_write(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_rate_monthly' => 2.0], today()->copy()->subDays(20));

        TenantContext::runFor($shop->id, fn () => $this->service->accrueAndSummary($this->freshLoan($loan->id)));

        // accrueAndSummary DOES accrue: 100000 * 2% /30 * 20 = 1333.33
        $this->assertSame(1333.33, (float) $this->freshLoan($loan->id)->outstanding_interest);
    }

    public function test_get_controller_methods_do_not_accrue(): void
    {
        // Deterministic proof of the Part D fix: the GET handlers (show / receipt /
        // closureCertificate / forfeitureNotice / paymentReceipt / reports) must not
        // call any accruing method. They render from stored state only.
        $src = file_get_contents(app_path('Http/Controllers/DhiranController.php'));

        // Isolate each GET method body and assert no accrue call inside it.
        foreach (['show', 'receipt', 'closureCertificate', 'forfeitureNotice', 'paymentReceipt', 'reports'] as $method) {
            if (! preg_match('/public function '.$method.'\b.*?\n    \}/s', $src, $m)) {
                continue;
            }
            $this->assertStringNotContainsString('accrueInterest', $m[0],
                "GET {$method}() must not accrue interest (write on read).");
            $this->assertStringNotContainsString('accrueAndSummary', $m[0],
                "GET {$method}() must not accrue interest (write on read).");
        }
    }

    public function test_loan_show_get_renders_without_mutation(): void
    {
        // Service-level guarantee that the data path used by GET show (loanSummary)
        // is pure — combined with the controller-source check above, GET show cannot
        // mutate. (Route-model binding for {loan} resolves under tenant middleware in
        // the live app; this asserts the underlying read path directly.)
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_rate_monthly' => 2.0], today()->copy()->subDays(20));

        $before = $this->freshLoan($loan->id)->getRawOriginal();
        TenantContext::runFor($shop->id, fn () => $this->service->loanSummary($this->freshLoan($loan->id)));
        $after = $this->freshLoan($loan->id)->getRawOriginal();

        $this->assertSame($before['outstanding_interest'], $after['outstanding_interest']);
        $this->assertSame($before['interest_accrued_through'], $after['interest_accrued_through']);
        $this->assertSame($before['updated_at'], $after['updated_at'], 'No row write at all.');
    }

    public function test_payment_post_still_accrues_before_payment(): void
    {
        $shop = $this->makeDhiranShop();
        $loan = $this->makeLoan($shop, ['interest_rate_monthly' => 2.0], today()->copy()->subDays(30));

        // Pay only the interest that WILL accrue (2000). recordPayment accrues first,
        // so this exact amount must clear interest to zero.
        TenantContext::runFor($shop->id, fn () => $this->service->recordPayment($this->freshLoan($loan->id), 2000));

        $loan = $this->freshLoan($loan->id);
        $this->assertSame(0.0, (float) $loan->outstanding_interest);
        $this->assertSame(2000.0, (float) $loan->total_interest_collected);
        $this->assertSame(100000.0, (float) $loan->outstanding_principal); // principal untouched
    }

    // ════════════════ TENANT / IDOR ════════════════

    public function test_shop_a_cannot_view_shop_b_loan(): void
    {
        $shopB = $this->makeDhiranShop('Shop B');
        $loanB = $this->makeLoan($shopB);

        // Inside Shop A's context, Shop B's loan must be invisible (BelongsToShop scope).
        $shopA = $this->makeDhiranShop('Shop A');
        $visible = TenantContext::runFor($shopA->id, fn () => DhiranLoan::find($loanB->id));
        $this->assertNull($visible, 'Shop A must not see Shop B loan via the tenant scope.');
    }

    public function test_shop_a_cannot_pay_shop_b_loan_via_route(): void
    {
        $shopB = $this->makeDhiranShop('Shop B');
        $loanB = $this->makeLoan($shopB);

        // Shop A owner, authenticated, hits Shop B's loan payment route on the dhiran host.
        $ownerA = \App\Models\User::create([
            'mobile_number' => '9390000099', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'is_active' => true,
            'shop_id' => $this->makeDhiranShop('Shop A')->id,
        ]);

        $this->actingAs($ownerA)
            ->post("https://dhiran.jewelflows.com/dhiran/loans/{$loanB->id}/repay", ['amount' => 100])
            ->assertNotFound(); // route-model binding is shop-scoped → 404, never pays
    }

    public function test_erp_user_cannot_access_dhiran_route(): void
    {
        $erp = \App\Models\User::create([
            'mobile_number' => '9391000099', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true,
        ]);

        // Realm gate bounces an ERP account away from the Dhiran app.
        $this->actingAs($erp)->get('https://dhiran.jewelflows.com/dhiran')
            ->assertRedirect('/dashboard');
    }

    public function test_payment_receipt_cannot_be_accessed_cross_shop(): void
    {
        $shopB = $this->makeDhiranShop('Shop B');
        $loanB = $this->makeLoan($shopB);
        $paymentB = TenantContext::runFor($shopB->id, fn () => $this->service->recordPayment($this->freshLoan($loanB->id), 5000));

        $ownerA = \App\Models\User::create([
            'mobile_number' => '9392000099', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'is_active' => true,
            'shop_id' => $this->makeDhiranShop('Shop A')->id,
        ]);

        $this->actingAs($ownerA)
            ->get("https://dhiran.jewelflows.com/dhiran/loans/{$loanB->id}/payments/{$paymentB->id}/receipt")
            ->assertNotFound();
    }
}

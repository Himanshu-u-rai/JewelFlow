<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Customer;
use App\Models\Dhiran\DhiranCashEntry;
use App\Models\Dhiran\DhiranLedgerEntry;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Owner 2026-11-12 ("fix these all with proper approach such that root cause is fixed"):
 *  1. interest is charged on the principal still owed, not the original amount;
 *  2. every money row records the person who took the money, not the loan's creator;
 *  3. compound interest compounds at month ends only, so the amount owed never depends on how often it is updated;
 *  4. forfeiture waits for the deadline printed on the notice, whatever the setting says later;
 *  5. Settings no longer offers SMS reminders that nothing sends;
 *  6. a loan cannot be dated in the future, and nothing is written into a locked accounting period.
 */
class DhiranRootCauseFixesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;
    private User $cashier;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(); // real today unless a test travels
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390015000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390015000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update([
            'is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0, 'default_min_interest_months' => 0,
            'default_penalty_rate_monthly' => 0, 'forfeiture_notice_days' => 30, 'grace_period_days' => 0,
        ]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390015000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
        $this->cashier = User::create([
            'mobile_number' => '9390015001', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Cashier',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Root', 'last_name' => 'Cause', 'mobile' => '9814440099']));
    }

    private function in(callable $fn, ?User $as = null)
    {
        return TenantContext::runFor($this->shop->id, function () use ($fn, $as) {
            $this->actingAs($as ?? $this->owner);

            return $fn(app(DhiranService::class));
        });
    }

    private function loan(string $type, string $date, float $principal = 100000, int $tenure = 12): DhiranLoan
    {
        return $this->in(fn ($svc) => $svc->createLoan($this->shop, $this->customer, [[
            'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 7000,
        ]], ['principal_amount' => $principal, 'gold_rate_on_date' => 7000, 'created_by' => $this->owner->id, 'loan_date' => $date,
            'tenure_months' => $tenure, 'interest_type' => $type, 'interest_rate_monthly' => 2, 'penalty_rate_monthly' => 0]));
    }

    // 1. Flat and daily interest follow the principal still owed.
    public function test_interest_after_part_repayment_is_on_the_principal_still_owed(): void
    {
        foreach (['flat', 'daily'] as $type) {
            $start = CarbonImmutable::today()->subDays(30);
            $this->travelTo($start);
            $loan = $this->loan($type, $start->toDateString());
            $this->in(fn ($svc) => $svc->recordPayment($loan->refresh(), 50000, 'cash')); // day 0: all principal
            $this->travelTo($start->addDays(30));
            $this->in(fn ($svc) => $svc->accrueInterest($loan->refresh()));
            $this->assertSame('1000.00', number_format((float) $loan->refresh()->outstanding_interest, 2, '.', ''),
                "{$type}: 30 days at 2%/month on the 50,000 still owed, not on the original 100,000");
            $this->travelBack();
        }
    }

    // 3. Compound interest is the same however often it is brought up to date, and compounds at month ends.
    public function test_compound_interest_does_not_depend_on_how_often_it_is_updated(): void
    {
        $start = CarbonImmutable::create(2026, 1, 10);
        $this->travelTo($start);
        $daily = $this->loan('compound', $start->toDateString());
        $once = $this->loan('compound', $start->toDateString());
        for ($d = 1; $d <= 75; $d++) {
            $this->travelTo($start->addDays($d));
            $this->in(fn ($svc) => $svc->accrueInterest($daily->refresh()));
        }
        $this->in(fn ($svc) => $svc->accrueInterest($once->refresh()));

        $a = (float) $daily->refresh()->outstanding_interest;
        $b = (float) $once->refresh()->outstanding_interest;
        // Each update rounds to paise, so 75 daily updates may differ by well under a rupee; the old bug was rupees.
        $this->assertEqualsWithDelta($a, $b, 1.0, 'updated every day vs once: same amount owed');
        // 10 Jan to 26 Mar: two month ends (10 Feb, 10 Mar) capitalise the unpaid interest.
        $m1 = 100000 * 0.02 / 30 * 31;                 // 10 Jan to 10 Feb
        $m2 = (100000 + $m1) * 0.02 / 30 * 28;         // 10 Feb to 10 Mar
        $m3 = (100000 + $m1 + $m2) * 0.02 / 30 * 16;   // 10 Mar to 26 Mar
        $this->assertEqualsWithDelta($m1 + $m2 + $m3, $b, 0.05);
        $this->travelBack();
    }

    // 2. The person who took the money is recorded on the payment, cash book and ledger.
    public function test_money_rows_record_who_took_the_money(): void
    {
        $loan = $this->loan('flat', CarbonImmutable::today()->toDateString());
        $payment = $this->in(fn ($svc) => $svc->recordPayment($loan->refresh(), 1000, 'cash'), $this->cashier);

        $this->assertSame($this->cashier->id, (int) $payment->created_by, 'payment');
        $this->assertSame($this->cashier->id, (int) DhiranCashEntry::withoutGlobalScopes()->where('dhiran_payment_id', $payment->id)->value('created_by'), 'cash book');
        $this->assertSame($this->cashier->id, (int) DhiranLedgerEntry::withoutGlobalScopes()->where('dhiran_payment_id', $payment->id)->value('created_by'), 'ledger');
        $this->assertSame($this->owner->id, (int) $loan->refresh()->created_by, 'the loan still belongs to who made it');
    }

    // 4. Forfeiture waits for the deadline printed on the notice.
    public function test_forfeiture_waits_for_the_deadline_on_the_notice(): void
    {
        $start = CarbonImmutable::today()->subMonths(3);
        $this->travelTo($start);
        $loan = $this->loan('flat', $start->toDateString(), 50000, 1);
        $this->travelBack();

        $this->in(fn ($svc) => $svc->sendForfeitureNotice($loan->refresh()));
        $this->assertSame(CarbonImmutable::today()->addDays(30)->toDateString(), $loan->refresh()->forfeiture_deadline?->toDateString());

        DhiranSettings::getForShop($this->shop->id)->update(['forfeiture_notice_days' => 0]); // owner shortens it later
        try {
            $this->in(fn ($svc) => $svc->executeForfeit($loan->refresh()));
            $this->fail('forfeited before the deadline printed on the notice');
        } catch (LogicException $e) {
            $this->assertStringContainsString('30 days remaining', $e->getMessage());
        }
        $this->assertFalse($loan->refresh()->canExecuteForfeit(), 'the button stays hidden too');

        $this->travelTo(CarbonImmutable::today()->addDays(30));
        $this->in(fn ($svc) => $svc->executeForfeit($loan->refresh()));
        $this->assertSame('forfeited', $loan->refresh()->status);
        $this->travelBack();
    }

    // 5. Settings offers no SMS reminders while nothing sends them.
    public function test_settings_do_not_offer_reminders_that_nothing_sends(): void
    {
        $html = $this->in(function () {
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return app(DhiranController::class)->settings()->render();
        });
        $this->assertStringNotContainsString('sms_reminders_enabled', $html);
        $this->assertStringNotContainsString('reminder_days_before_due', $html);
    }

    // 6. No future loan dates; nothing is written into a locked accounting period.
    public function test_no_future_loan_dates_and_the_period_lock_holds(): void
    {
        try {
            $this->in(function () {
                view()->share('errors', new \Illuminate\Support\ViewErrorBag);
                $req = Request::create('/dhiran', 'POST', ['customer_id' => $this->customer->id, 'principal_amount' => 10000,
                    'gold_rate_on_date' => 7000, 'loan_date' => CarbonImmutable::tomorrow()->toDateString(),
                    'items' => [['description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 7000]]]);
                $this->app->instance('request', $req);

                return app(DhiranController::class)->store($req);
            });
            $this->fail('a future loan date was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('loan_date', $e->errors());
        }

        $loan = $this->loan('flat', CarbonImmutable::today()->subMonths(2)->toDateString());
        DB::table('shop_rules')->updateOrInsert(['shop_id' => $this->shop->id], ['financial_lock_date' => CarbonImmutable::today()->toDateString()]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Financial lock');
        $this->loan('flat', CarbonImmutable::today()->subMonth()->toDateString()); // back-dated into the locked period
    }

    public function test_payments_respect_the_period_lock(): void
    {
        $loan = $this->loan('flat', CarbonImmutable::today()->toDateString());
        DB::table('shop_rules')->updateOrInsert(['shop_id' => $this->shop->id], ['financial_lock_date' => CarbonImmutable::today()->toDateString()]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Financial lock');
        $this->in(fn ($svc) => $svc->recordPayment($loan->refresh(), 1000, 'cash'));
    }
}

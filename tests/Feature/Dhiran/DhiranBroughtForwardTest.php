<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranCashEntry;
use App\Models\Dhiran\DhiranLedgerEntry;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner 2026-11-12: a shop joining Dhiran brings its running paper loans in as they stand today ("brought forward").
 * The paper book is the truth: the ticket number, the date, the principal still owed and the date interest was paid
 * up to. Nothing is booked in the cash book (that money moved before the shop used the app); one ledger line carries
 * the opening balance; interest runs from the paid-up date by the app's rules unless the shop enters its own figure.
 */
class DhiranBroughtForwardTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://dhiran.jewelflows.com';
    private Shop $shop;
    private User $owner;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390019000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390019000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 500, 'processing_fee_type' => 'flat',
            'default_min_lock_months' => 0, 'default_min_interest_months' => 0, 'default_penalty_rate_monthly' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390019000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Paper', 'last_name' => 'Loan', 'mobile' => '9819990001']));
    }

    private function form(array $over = []): array
    {
        return $over + [
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
            'customer_id' => $this->customer->id, 'principal_amount' => 100000, 'gold_rate_on_date' => 6000,
            'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 12,
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]],
            'brought_forward' => 1, 'paper_loan_number' => 'P-778',
            'loan_date' => CarbonImmutable::today()->subMonths(10)->toDateString(),
            'principal_owed' => 60000,
            'interest_paid_until' => CarbonImmutable::today()->subDays(30)->toDateString(),
        ];
    }

    private function send(array $data)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->post(self::HOST . '/dhiran', $data));
    }

    private function onlyLoan(): DhiranLoan
    {
        return DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->sole();
    }

    public function test_a_paper_loan_comes_in_as_it_stands_today(): void
    {
        $this->send($this->form())->assertSessionHasNoErrors()->assertRedirect();
        $loan = $this->onlyLoan();

        $this->assertTrue((bool) $loan->brought_forward);
        $this->assertSame('P-778', $loan->loan_number, 'the ticket number the borrower holds');
        $this->assertSame(CarbonImmutable::today()->subMonths(10)->toDateString(), $loan->loan_date->toDateString());
        $this->assertSame(100000.0, (float) $loan->principal_amount);
        $this->assertSame(60000.0, (float) $loan->outstanding_principal);
        $this->assertSame(40000.0, (float) $loan->total_principal_collected, 'repaid on paper before');
        $this->assertSame(0.0, (float) $loan->processing_fee, 'no fee on a loan made before the app');
        $this->assertSame(0, (int) $loan->min_interest_months, 'minimums were settled on paper');
        $page = TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(self::HOST . "/dhiran/loans/{$loan->id}")->getContent());
        $this->assertStringContainsString('data-brought-forward-note', $page);

        $this->assertSame(0, DhiranCashEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->count(), 'no cash moved in the app');
        $this->assertSame(0, DhiranPayment::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->count());
        $ledger = DhiranLedgerEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->sole();
        $this->assertSame('brought_forward', $ledger->entry_type);
        $this->assertSame(60000.0, (float) $ledger->balance_after);

        // Interest runs from the paid-up date by the app's rules: 30 days at 2%/month flat on 60,000.
        $loan->forceFill(['status' => 'active'])->saveQuietly();
        TenantContext::runFor($this->shop->id, fn () => app(DhiranService::class)->accrueInterest($loan->refresh()));
        $this->assertSame('1200.00', number_format((float) $loan->refresh()->outstanding_interest, 2, '.', ''));
    }

    public function test_the_shops_own_unpaid_interest_figure_is_kept(): void
    {
        $this->send($this->form(['unpaid_interest' => 1234.5]))->assertSessionHasNoErrors();
        $loan = $this->onlyLoan();
        $this->assertSame(1234.5, (float) $loan->outstanding_interest);
        $this->assertSame(CarbonImmutable::today()->toDateString(), $loan->interest_accrued_through->toDateString(), 'the app adds interest only from today');
        $this->assertSame(1234.5, (float) DhiranLedgerEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->value('interest_balance_after'));
    }

    public function test_old_loans_are_not_refused_by_todays_lending_rules_or_a_closed_period(): void
    {
        DB::table('shop_rules')->updateOrInsert(['shop_id' => $this->shop->id], ['financial_lock_date' => CarbonImmutable::today()->subMonth()->toDateString()]);
        // 400,000 is above today's LTV ceiling for this chain; on paper it was lent long ago.
        $this->send($this->form(['principal_amount' => 400000, 'principal_owed' => 300000]))->assertSessionHasNoErrors();
        $this->assertSame(300000.0, (float) $this->onlyLoan()->outstanding_principal);
    }

    public function test_impossible_paper_figures_are_refused(): void
    {
        $this->send($this->form(['principal_owed' => 150000]))->assertSessionHasErrors('principal_owed');
        $this->send($this->form(['interest_paid_until' => CarbonImmutable::today()->subMonths(11)->toDateString()]))->assertSessionHasErrors('interest_paid_until');
        $this->send($this->form(['interest_paid_until' => CarbonImmutable::tomorrow()->toDateString()]))->assertSessionHasErrors('interest_paid_until');
        $this->send($this->form(['paper_loan_number' => '']))->assertSessionHasErrors('paper_loan_number');
        $this->send($this->form())->assertSessionHasNoErrors();
        $this->send($this->form())->assertSessionHasErrors('paper_loan_number'); // same ticket twice
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    public function test_cancelling_a_brought_forward_loan_books_no_cash(): void
    {
        $this->send($this->form());
        $loan = $this->onlyLoan();
        TenantContext::runFor($this->shop->id, function () use ($loan) {
            $this->actingAs($this->owner);
            app(DhiranService::class)->cancelPendingLoan($loan, 'entered by mistake');
        });
        $this->assertSame('cancelled', $loan->refresh()->status);
        $this->assertSame(0, DhiranCashEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->count());
        $this->assertSame(0.0, (float) DhiranLedgerEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->latest('id')->value('balance_after'));
    }

    public function test_the_new_loan_form_offers_brought_forward_entry(): void
    {
        $page = TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(self::HOST . '/dhiran/create')->getContent());
        foreach (['brought_forward', 'paper_loan_number', 'loan_date', 'principal_owed', 'interest_paid_until', 'unpaid_interest'] as $field) {
            $this->assertStringContainsString("name=\"{$field}\"", $page, $field);
        }
    }
}

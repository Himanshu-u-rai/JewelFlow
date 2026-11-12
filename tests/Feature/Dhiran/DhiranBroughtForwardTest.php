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
use App\Models\ShopEditionAssignment;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner 2026-11-12: the Old Loans module brings a shop's running paper loans in, borrower and loan together, as they
 * stand today. The paper book is the truth: ticket number, date, principal still owed, the date interest was paid up
 * to. Nothing goes in the cash book; one ledger line carries the opening balance; interest runs from the paid-up date
 * unless the shop gives its own figure. Every loan has collateral. Loans are active at once; missing photo or ID proof
 * is flagged, not blocking. The daily New Loan page is not involved.
 */
class DhiranBroughtForwardTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://dhiran.jewelflows.com';
    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390019000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390019000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 500, 'processing_fee_type' => 'flat',
            'default_min_lock_months' => 0, 'default_min_interest_months' => 3, 'default_penalty_rate_monthly' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390019000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
    }

    private function form(array $over = []): array
    {
        return $over + [
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
            'customer_id' => '', // the form always sends the picker; empty = a new borrower
            'borrower' => ['first_name' => 'Paper', 'last_name' => 'Loan', 'mobile' => '9819990001', 'address' => 'Old Market', 'pan' => 'abcde1234f'],
            'paper_loan_number' => 'P-778',
            'loan_date' => CarbonImmutable::today()->subMonths(10)->toDateString(),
            'principal_amount' => 100000, 'principal_owed' => 60000,
            'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 12,
            'interest_paid_until' => CarbonImmutable::today()->subDays(30)->toDateString(),
            'gold_rate_on_date' => 6000,
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]],
        ];
    }

    private function in(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, function () use ($fn) {
            $this->actingAs($this->owner);

            return $fn();
        });
    }

    private function send(array $data)
    {
        return $this->in(fn () => $this->post(self::HOST . '/dhiran/old-loans', $data));
    }

    private function onlyLoan(): DhiranLoan
    {
        return DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->sole();
    }

    public function test_an_old_customer_and_their_loan_come_in_together_as_they_stand_today(): void
    {
        $this->send($this->form())->assertSessionHasNoErrors()->assertRedirect();
        $loan = $this->onlyLoan();
        $customer = Customer::withoutGlobalScopes()->findOrFail($loan->customer_id);

        $this->assertSame('Paper', $customer->first_name);
        $this->assertSame('ABCDE1234F', $customer->pan);
        $this->assertSame('active', $loan->status, 'active at once, so payments can be taken from day one');
        $this->assertTrue((bool) $loan->brought_forward);
        $this->assertSame('P-778', $loan->loan_number, 'the ticket number the borrower holds');
        $this->assertSame(CarbonImmutable::today()->subMonths(10)->toDateString(), $loan->loan_date->toDateString());
        $this->assertSame(100000.0, (float) $loan->principal_amount);
        $this->assertSame(60000.0, (float) $loan->outstanding_principal);
        $this->assertSame(40000.0, (float) $loan->total_principal_collected, 'repaid on paper before');
        $this->assertSame(0.0, (float) $loan->processing_fee, 'no fee on a loan made before');
        $this->assertSame(0, (int) $loan->min_interest_months, 'minimums were settled on paper');

        $this->assertSame(0, DhiranCashEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->count(), 'no cash moved here');
        $this->assertSame(0, DhiranPayment::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->count());
        $ledger = DhiranLedgerEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->sole();
        $this->assertSame('brought_forward', $ledger->entry_type);
        $this->assertSame(60000.0, (float) $ledger->balance_after);

        // Interest runs from the paid-up date by the app's rules: 30 days at 2%/month flat on 60,000.
        $this->in(fn () => app(DhiranService::class)->accrueInterest($loan->refresh()));
        $this->assertSame('1200.00', number_format((float) $loan->refresh()->outstanding_interest, 2, '.', ''));
    }

    public function test_a_borrower_already_on_the_books_is_reused(): void
    {
        $existing = $this->in(fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Known', 'last_name' => 'One', 'mobile' => '9819990001']));
        $this->send($this->form())->assertSessionHasNoErrors();                                  // same mobile, typed again
        $this->send($this->form(['customer_id' => $existing->id, 'paper_loan_number' => 'P-779']))->assertSessionHasNoErrors(); // picked
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
        $this->assertSame([$existing->id], DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->distinct()->pluck('customer_id')->all());
    }

    public function test_the_shops_own_unpaid_interest_figure_is_kept(): void
    {
        $this->send($this->form(['unpaid_interest' => 1234.5]))->assertSessionHasNoErrors();
        $loan = $this->onlyLoan();
        $this->assertSame(1234.5, (float) $loan->outstanding_interest);
        $this->assertSame(CarbonImmutable::today()->toDateString(), $loan->interest_accrued_through->toDateString(), 'interest is added only from today');
        $this->assertSame(1234.5, (float) DhiranLedgerEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->value('interest_balance_after'));
    }

    public function test_old_loans_are_not_refused_by_todays_lending_rules_or_a_closed_period(): void
    {
        DB::table('shop_rules')->updateOrInsert(['shop_id' => $this->shop->id], ['financial_lock_date' => CarbonImmutable::today()->subMonth()->toDateString()]);
        $this->send($this->form(['principal_amount' => 400000, 'principal_owed' => 300000]))->assertSessionHasNoErrors();
        $this->assertSame(300000.0, (float) $this->onlyLoan()->outstanding_principal);
    }

    public function test_impossible_figures_and_loans_without_collateral_are_refused(): void
    {
        $this->send($this->form(['principal_owed' => 150000]))->assertSessionHasErrors('principal_owed');
        $this->send($this->form(['interest_paid_until' => CarbonImmutable::today()->subMonths(11)->toDateString()]))->assertSessionHasErrors('interest_paid_until');
        $this->send($this->form(['interest_paid_until' => CarbonImmutable::tomorrow()->toDateString()]))->assertSessionHasErrors('interest_paid_until');
        $this->send($this->form(['paper_loan_number' => '']))->assertSessionHasErrors('paper_loan_number');
        $this->send($this->form(['items' => []]))->assertSessionHasErrors('items');
        $this->send($this->form(['borrower' => ['first_name' => 'X', 'mobile' => '12']]))->assertSessionHasErrors('borrower.mobile');
        $this->send($this->form())->assertSessionHasNoErrors();
        $this->send($this->form())->assertSessionHasErrors('paper_loan_number'); // same ticket twice
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    public function test_missing_evidence_is_flagged_not_blocking(): void
    {
        $this->send($this->form());
        $loan = $this->onlyLoan();

        $page = fn (string $q = '') => $this->in(fn () => $this->get(self::HOST . '/dhiran/old-loans' . $q)->assertOk()->getContent());
        $this->assertStringContainsString('P-778', $page('?evidence=missing'));
        $this->assertMatchesRegularExpression('/data-evidence="missing"/', $page());
        $this->assertStringContainsString('data-evidence-missing', $this->in(fn () => $this->get(self::HOST . "/dhiran/loans/{$loan->id}")->getContent()));

        foreach ([['dhiran_loan_item', $loan->items()->withoutGlobalScopes()->value('id'), 'item_photo'], ['customer', $loan->customer_id, 'id_proof_front']] as [$type, $id, $doc]) {
            DB::table('dhiran_attachments')->insert(['shop_id' => $this->shop->id, 'owner_type' => $type, 'owner_id' => $id, 'document_type' => $doc,
                'file_path' => "x/{$doc}.jpg", 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertStringNotContainsString('P-778', $page('?evidence=missing'));
        $this->assertStringContainsString('P-778', $page('?evidence=done'));
    }

    public function test_an_old_loan_entered_by_mistake_can_be_removed_until_money_moves(): void
    {
        $this->send($this->form());
        $loan = $this->onlyLoan();
        $this->in(fn () => $this->post(self::HOST . "/dhiran/loans/{$loan->id}/cancel", ['reason' => 'wrong figures', 'idempotency_key' => 'bf-cancel-1']))
            ->assertSessionHas('success');
        $this->assertSame('cancelled', $loan->refresh()->status);
        $this->assertSame(0, DhiranCashEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->count(), 'no cash either way');
        $this->assertSame(0.0, (float) DhiranLedgerEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->latest('id')->value('balance_after'));

        $this->send($this->form(['paper_loan_number' => 'P-780']));
        $paid = DhiranLoan::withoutGlobalScopes()->where('loan_number', 'P-780')->sole();
        $this->in(fn () => app(DhiranService::class)->recordPayment($paid, 1000, 'cash'));
        $this->in(fn () => $this->post(self::HOST . "/dhiran/loans/{$paid->id}/cancel", ['idempotency_key' => 'bf-cancel-2']))->assertSessionHas('error');
        $this->assertSame('active', $paid->refresh()->status, 'a loan with payments is settled the normal way');
    }

    public function test_the_module_is_its_own_page_found_in_the_menu_and_waits_for_a_paid_plan(): void
    {
        $this->in(fn () => $this->get(self::HOST . '/dhiran/old-loans/create'))->assertOk()->assertSee('name="paper_loan_number"', false);
        $this->in(fn () => $this->get(self::HOST . '/dhiran'))->assertSee(route('dhiran.old-loans.index'), false);

        ShopEditionAssignment::where('shop_id', $this->shop->id)->where('edition', 'dhiran')->update(['deactivated_at' => now()]);
        $this->owner = $this->owner->fresh(); // the signed-in user's shop editions were loaded by the requests above
        $this->in(fn () => $this->get(self::HOST . '/dhiran/old-loans/create'))->assertRedirect(route('dhiran.dashboard'));
        $this->in(fn () => $this->get(self::HOST . '/dhiran/old-loans'))->assertOk();
    }
}

<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Owner 2026-11-12, state-machine audit: every loan state change is decided on the row as it is under the lock, and
 * the database refuses a status that is not a loan state.
 */
class DhiranStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390017000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390017000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update([
            'is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0, 'default_min_interest_months' => 0,
            'default_penalty_rate_monthly' => 0, 'forfeiture_notice_days' => 0, 'grace_period_days' => 0,
        ]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390017000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'State', 'last_name' => 'Machine', 'mobile' => '9817770001']));
    }

    private function in(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, function () use ($fn) {
            $this->actingAs($this->owner);

            return $fn(app(DhiranService::class));
        });
    }

    private function loan(?string $date = null, int $tenure = 12, array $extra = []): DhiranLoan
    {
        return $this->in(fn ($svc) => $svc->createLoan($this->shop, $this->customer, [[
            'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 7000,
        ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 7000, 'created_by' => $this->owner->id,
            'loan_date' => $date ?? CarbonImmutable::today()->toDateString(), 'tenure_months' => $tenure, 'interest_rate_monthly' => 2] + $extra));
    }

    // H1. Two people act on one loan: the second action sees the loan as the first one left it, not as it was when
    // their page loaded, so a pre-closed loan cannot then be forfeited, renewed, paid or closed again.
    public function test_a_loan_finished_by_someone_else_cannot_be_acted_on_from_a_stale_copy(): void
    {
        $actions = [
            'pay'         => fn ($svc, $l) => $svc->recordPayment($l, 1000, 'cash'),
            'pay interest' => fn ($svc, $l) => $svc->recordInterestPayment($l, 100, 'cash'),
            'release'     => fn ($svc, $l, $item) => $svc->releaseItem($l, $item, 200000, 'cash', ''),
            'pre-close'   => fn ($svc, $l) => $svc->preCloseLoan($l, 'cash'),
            'renew'       => fn ($svc, $l) => $svc->renewLoan($l, null, null, 'cash'),
            'close'       => function ($svc, $l) { $l->outstanding_principal = 0; $l->outstanding_interest = 0; $l->outstanding_penalty = 0; $svc->closeLoan($l); },
            'notice'      => fn ($svc, $l) => $svc->sendForfeitureNotice($l),
            'forfeit'     => fn ($svc, $l) => $svc->executeForfeit($l),
        ];

        foreach ($actions as $name => $act) {
            $start = CarbonImmutable::today()->subMonths(3);
            $this->travelTo($start);
            $loan = $this->loan($start->toDateString(), 1);
            $this->travelBack();
            $loan->forceFill(['forfeiture_notice_sent_at' => now()->subDays(5), 'forfeiture_deadline' => today()->subDay()])->save();

            $stale = $this->in(fn () => DhiranLoan::find($loan->id)); // the page someone else still has open
            $staleItem = $this->in(fn () => $stale->items()->first());
            $this->in(fn ($svc) => $svc->preCloseLoan(DhiranLoan::find($loan->id), 'cash')); // first person settles it

            try {
                $this->in(fn ($svc) => $act($svc, $stale, $staleItem));
                $this->fail("{$name} went through on a loan that was already closed");
            } catch (LogicException $e) {
                $this->assertStringContainsString('closed', $e->getMessage(), $name);
            }
            $this->assertSame('closed', $loan->refresh()->status, "{$name} changed a closed loan");
        }
    }

    // H2. A renewed loan owes nothing (its balance moved to the new loan), its page says where its items went, and the
    // borrower's totals count the principal once, also for loans renewed before this fix.
    public function test_a_renewed_loan_owes_nothing_and_is_counted_once(): void
    {
        $old = $this->loan();
        $new = $this->in(fn ($svc) => $svc->renewLoan($old->refresh(), null, null, 'cash'));

        $this->assertSame('renewed', $old->refresh()->status);
        $this->assertSame(0.0, (float) $old->outstanding_principal, 'the balance moved to the new loan');
        $this->assertSame(100000.0, (float) $new->outstanding_principal);

        $old->forceFill(['outstanding_principal' => 100000])->saveQuietly(); // a loan renewed before this fix
        $summary = $this->in(fn () => $this->get("https://dhiran.jewelflows.com/dhiran/borrowers/{$this->customer->id}")->assertOk()->viewData('summary'));
        $this->assertSame(100000.0, (float) $summary['principal_outstanding'], 'principal counted once, not on the renewed loan too');
        $this->assertSame(0.0, $old->refresh()->totalOutstanding(), 'a finished loan owes nothing, whatever an old row says');
        $totals = $this->in(fn () => $this->get('https://dhiran.jewelflows.com/dhiran/reports/profitability')->assertOk()->viewData('totals'));
        $this->assertSame(100000.0, round($totals['outstanding'], 2), 'report totals count the moved principal once');

        $page = $this->in(fn () => $this->get("https://dhiran.jewelflows.com/dhiran/loans/{$old->id}")->getContent());
        $this->assertStringContainsString("Items moved to {$new->loan_number}", $page);
    }

    public function test_the_database_refuses_a_status_that_is_not_a_loan_state(): void
    {
        $loan = $this->loan();
        $this->expectException(QueryException::class);
        DB::table('dhiran_loans')->where('id', $loan->id)->update(['status' => 'open']);
    }
}

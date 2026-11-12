<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\NotAvailable;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner 2026-11-12 (L3, options 1 + 2): when the Dhiran plan ends, the shop can no longer start new business (new
 * loans, renewals, new borrowers) but can always settle existing loans and see its books; and interest keeps
 * following each loan whatever the plan or the settings switch says.
 */
class DhiranLapsedPlanTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://dhiran.jewelflows.com';
    private Shop $shop;
    private User $owner;
    private User $staff;
    private DhiranLoan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390018000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390018000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0, 'default_min_interest_months' => 0]);
        $all = Permission::query()->pluck('id');
        $ownerRole = new Role();
        $ownerRole->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $ownerRole->permissions()->sync($all);
        $staffRole = new Role();
        $staffRole->forceFill(['name' => 'cashier', 'display_name' => 'Cashier', 'shop_id' => $this->shop->id])->save();
        $staffRole->permissions()->sync($all);
        $this->owner = User::create(['mobile_number' => '9390018000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $ownerRole->id]);
        $this->staff = User::create(['mobile_number' => '9390018001', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Cashier',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $staffRole->id]);
        $customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Lapsed', 'last_name' => 'Plan', 'mobile' => '9818880001']));
        $this->loan = TenantContext::runFor($this->shop->id, fn () => app(DhiranService::class)->createLoan($this->shop, $customer, [[
            'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
        ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id, 'interest_rate_monthly' => 2]));
    }

    private function lapse(): void
    {
        ShopEditionAssignment::where('shop_id', $this->shop->id)->where('edition', 'dhiran')->update(['deactivated_at' => now()]);
    }

    /** The settings switch off without its edition hook (the state a lapsed shop can be in). */
    private function switchOff(): void
    {
        \Illuminate\Support\Facades\DB::table('dhiran_settings')->where('shop_id', $this->shop->id)->update(['is_enabled' => \Illuminate\Support\Facades\DB::raw('false')]);
    }

    private function as(User $user, string $method, string $path, array $data = [])
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($user)->{$method}(self::HOST . $path, $data));
    }

    public function test_a_lapsed_plan_still_shows_the_books_and_takes_collections(): void
    {
        $this->lapse();
        $id = $this->loan->id;

        foreach (['/dhiran/loans', "/dhiran/loans/{$id}", '/dhiran/borrowers', '/dhiran/reports/cashbook'] as $page) {
            $this->as($this->owner, 'get', $page)->assertOk();
        }
        $this->travelTo(CarbonImmutable::today()->addDays(30)); // some interest is due
        $this->as($this->staff, 'post', "/dhiran/loans/{$id}/pay-interest", ['amount' => 100, 'idempotency_key' => 'lapse-0001'])->assertSessionHas('success');
        $this->as($this->staff, 'post', "/dhiran/loans/{$id}/repay", ['amount' => 5000, 'idempotency_key' => 'lapse-0002'])->assertSessionHas('success');
        $this->assertSame(2, DhiranPayment::withoutGlobalScopes()->where('dhiran_loan_id', $id)->where('direction', 'in')->count());
        $this->travelBack();
    }

    public function test_a_lapsed_plan_stops_new_business_and_only_the_owner_hears_why(): void
    {
        $this->lapse();
        $id = $this->loan->id;

        $this->as($this->owner, 'get', '/dhiran/create')->assertRedirect(route('dhiran.dashboard'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Renew') && str_contains($m, 'collections'));
        $this->as($this->staff, 'get', '/dhiran/create')->assertSessionHas('error', NotAvailable::MESSAGE);

        $this->as($this->owner, 'post', "/dhiran/loans/{$id}/renew", ['idempotency_key' => 'lapse-0003'])->assertRedirect(route('dhiran.dashboard'));
        $this->assertSame('active', $this->loan->refresh()->status, 'not renewed');
        $this->as($this->owner, 'post', '/dhiran/customers', ['first_name' => 'New', 'mobile' => '9818880002'])->assertRedirect(route('dhiran.dashboard'));
        $this->assertSame(1, TenantContext::runFor($this->shop->id, fn () => Customer::count()), 'no new borrower');

        $this->as($this->owner, 'get', '/dhiran')->assertOk()->assertSee('data-dh-plan-lapsed', false);
        $this->as($this->staff, 'get', '/dhiran')->assertOk()->assertDontSee('data-dh-plan-lapsed', false)->assertDontSee('plan has ended');
    }

    public function test_a_shop_that_never_switched_dhiran_on_is_still_kept_out(): void
    {
        $this->lapse();
        $this->switchOff();
        $this->as($this->owner, 'get', '/dhiran/loans')->assertRedirect(route('dhiran.dashboard'));
    }

    public function test_interest_follows_the_loan_whatever_the_plan_or_switch_says(): void
    {
        $this->lapse();
        $this->switchOff();

        $this->travelTo(CarbonImmutable::today()->addDays(30));
        $this->artisan('dhiran:accrue-interest')->assertSuccessful();
        $this->assertGreaterThan(0, (float) $this->loan->refresh()->outstanding_interest, 'interest kept accruing');
        $this->artisan('dhiran:forfeiture-check')->expectsOutputToContain("Shop #{$this->shop->id}")->assertSuccessful();
        $this->travelBack();
    }
}

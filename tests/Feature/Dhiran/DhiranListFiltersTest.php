<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Owner 2026-11-08: Dhiran lists and reports had almost no filters. One filter bar (period presets incl. quarters and
 * the Indian financial year, status, metal, amount range, overdue band, maturing soon, search) is shared by the Loans
 * list and the reports, applied on the server, so totals and page links follow the filters.
 */
class DhiranListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;
    private Customer $asha;
    private Customer $ravi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390007000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390007000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390007000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
        [$this->asha, $this->ravi] = TenantContext::runFor($this->shop->id, fn () => [
            Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Asha', 'last_name' => 'Rao', 'mobile' => '9816660001']),
            Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Ravi', 'last_name' => 'Das', 'mobile' => '9816660002']),
        ]);
    }

    private function loan(Customer $c, string $date, float $principal, string $metal = 'gold', int $tenure = 6): DhiranLoan
    {
        return TenantContext::runFor($this->shop->id, fn () => app(DhiranService::class)->createLoan($this->shop, $c, [[
            'description' => 'Item', 'metal_type' => $metal, 'purity' => $metal === 'silver' ? 925 : 22,
            'gross_weight' => $metal === 'silver' ? 5000 : 100, 'rate_per_gram_at_pledge' => $metal === 'silver' ? 90 : 7000,
        ]], ['principal_amount' => $principal, 'gold_rate_on_date' => 7000, 'silver_rate_on_date' => 90, 'created_by' => $this->owner->id,
            'loan_date' => $date, 'tenure_months' => $tenure]));
    }

    private function page(string $method, array $query = []): string
    {
        return TenantContext::runFor($this->shop->id, function () use ($method, $query) {
            $this->actingAs($this->owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $req = Request::create('/dhiran/x', 'GET', $query);
            $this->app->instance('request', $req);

            return app(DhiranController::class)->{$method}($req)->render();
        });
    }

    private function assertShows(string $html, array $shown, array $hidden, string $why): void
    {
        foreach ($shown as $l) $this->assertStringContainsString($l->loan_number, $html, "{$why}: shows {$l->loan_number}");
        foreach ($hidden as $l) $this->assertStringNotContainsString($l->loan_number, $html, "{$why}: hides {$l->loan_number}");
    }

    public function test_loans_list_filters_by_period_metal_amount_status_and_search(): void
    {
        $today = CarbonImmutable::today();
        $fyStart = CarbonImmutable::create($today->month >= 4 ? $today->year : $today->year - 1, 4, 1);
        $thisFy = $this->loan($this->asha, $fyStart->addDays(2)->toDateString(), 50000);
        $lastFy = $this->loan($this->asha, $fyStart->subDays(10)->toDateString(), 150000);
        $silver = $this->loan($this->ravi, $today->toDateString(), 20000, 'silver');

        $this->assertShows($this->page('loans', ['period' => 'this_fy']), [$thisFy, $silver], [$lastFy], 'this FY');
        $this->assertShows($this->page('loans', ['period' => 'last_fy']), [$lastFy], [$thisFy, $silver], 'last FY');
        $this->assertShows($this->page('loans', ['period' => 'custom', 'from' => $fyStart->subDays(11)->toDateString(), 'to' => $fyStart->subDays(9)->toDateString()]), [$lastFy], [$thisFy, $silver], 'custom dates');
        $this->assertShows($this->page('loans', ['metal' => 'silver']), [$silver], [$thisFy, $lastFy], 'silver');
        $this->assertShows($this->page('loans', ['amount_min' => 40000, 'amount_max' => 100000]), [$thisFy], [$lastFy, $silver], 'amount range');
        $this->assertShows($this->page('loans', ['search' => 'Ravi']), [$silver], [$thisFy, $lastFy], 'search');
        $this->assertShows($this->page('loans', ['search' => 'Asha', 'amount_min' => 100000]), [$lastFy], [$thisFy, $silver], 'combined');
    }

    public function test_overdue_bands_and_maturing_soon(): void
    {
        $today = CarbonImmutable::today();
        $soon = $this->loan($this->asha, $today->subMonthNoOverflow()->addDays(5)->toDateString(), 30000, 'gold', 1);   // matures in ~5 days
        $late10 = $this->loan($this->asha, $today->subMonthNoOverflow()->subDays(10)->toDateString(), 30000, 'gold', 1);
        $late60 = $this->loan($this->asha, $today->subMonthNoOverflow()->subDays(60)->toDateString(), 30000, 'gold', 1);
        $late120 = $this->loan($this->asha, $today->subMonthNoOverflow()->subDays(120)->toDateString(), 30000, 'gold', 1);

        $this->assertShows($this->page('loans', ['overdue' => '1-30']), [$late10], [$soon, $late60, $late120], '1-30 days late');
        $this->assertShows($this->page('loans', ['overdue' => '31-90']), [$late60], [$soon, $late10, $late120], '31-90 days late');
        $this->assertShows($this->page('loans', ['overdue' => '90+']), [$late120], [$soon, $late10, $late60], 'over 90 days late');
        $this->assertShows($this->page('loans', ['maturing' => '7']), [$soon], [$late10, $late60, $late120], 'maturing in 7 days');
        $overdueReport = $this->page('reportOverdue', ['overdue' => '31-90']);
        $this->assertShows($overdueReport, [$late60], [$late10, $late120], 'overdue report band');
        $this->assertStringContainsString('data-report-total="count">1<', $overdueReport, 'cards follow the filter');
    }

    public function test_reports_take_the_same_bar_and_their_totals_follow_it(): void
    {
        $today = CarbonImmutable::today()->toDateString();
        $gold = $this->loan($this->asha, $today, 50000);
        $silver = $this->loan($this->ravi, $today, 20000, 'silver');

        $active = $this->page('reportActive', ['metal' => 'silver']);
        $this->assertShows($active, [$silver], [$gold], 'active report metal');
        $this->assertStringContainsString('data-report-total="principal">20,000.00<', $active);

        $profit = $this->page('reportProfitability', ['period' => 'this_month', 'search' => 'Asha']);
        $this->assertShows($profit, [$gold], [$silver], 'profitability search');
        $this->assertStringContainsString('data-report-total="principal">50,000.00<', $profit);

        // Cash book: payouts are "out"; the in/out filter narrows the rows, the balances stay whole.
        $cash = $this->page('reportCashbook', ['period' => 'this_month', 'cash' => 'in']);
        $this->assertStringContainsString('No cash entries found', $cash);
        $this->assertStringContainsString('data-report-total="out">70,000.00<', $this->page('reportCashbook', ['period' => 'this_month']));

        // Each page draws the bar with its own fields.
        $bar = $this->page('loans');
        foreach (['name="search"', 'name="period"', 'name="status"', 'name="metal"', 'name="amount_min"', 'name="amount_max"', 'name="overdue"', 'name="maturing"'] as $field) {
            $this->assertStringContainsString($field, $bar);
        }
        $this->assertStringContainsString('This financial year', $bar);
        $this->assertStringContainsString('This quarter', $bar);
    }

    // Owner 2026-11-08: modern filters, not rows of dropdowns: a search box and a Filters button; the choices are tap
    // chips in a panel (a bottom sheet on phones, a side drawer on desktop); active filters show as removable chips.
    public function test_filters_open_in_a_panel_of_chips_and_active_ones_show_as_removable_chips(): void
    {
        $html = $this->page('loans', ['metal' => 'silver', 'period' => 'this_fy', 'search' => 'Ravi', 'amount_min' => 1000]);
        $this->assertMatchesRegularExpression('/<dialog[^>]*data-filter-panel/', $html, 'the panel is a dialog');
        $this->assertMatchesRegularExpression('/<input type="radio" name="metal" value="silver"[^>]*checked/', $html, 'metal is a chip group');
        $this->assertMatchesRegularExpression('/<input type="radio" name="period" value="this_fy"[^>]*checked/', $html);
        $this->assertStringContainsString('data-filter-count>3<', $html, 'search is not counted; metal, period, amount are');

        $chips = substr($html, strpos($html, 'data-active-filters'));
        foreach (['Silver', 'This financial year', 'From ₹1,000', '“Ravi”'] as $label) {
            $this->assertStringContainsString($label, $chips);
        }
        // Each chip links to the same list without that one filter.
        $base = route('dhiran.loans');
        $this->assertStringContainsString('href="' . e($base . '?' . http_build_query(['period' => 'this_fy', 'amount_min' => 1000, 'search' => 'Ravi'])) . '"', $chips, 'remove metal');
        $this->assertStringContainsString('href="' . e($base . '?' . http_build_query(['period' => 'this_fy', 'metal' => 'silver', 'amount_min' => 1000])) . '"', $chips, 'remove search');
        $this->assertStringContainsString('href="' . e($base) . '"', $chips, 'clear all');
    }

    public function test_old_from_to_links_still_work_and_bad_input_is_refused(): void
    {
        $old = $this->loan($this->asha, '2025-01-15', 40000);
        $this->assertShows($this->page('reportProfitability', ['from' => '2025-01-01', 'to' => '2025-01-31']), [$old], [], 'from/to without period');

        foreach ([['period' => 'yesterday-ish'], ['metal' => 'platinum'], ['amount_min' => -5], ['overdue' => '5'], ['period' => 'custom', 'from' => '2025-02-01', 'to' => '2025-01-01']] as $bad) {
            try {
                $this->page('loans', $bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

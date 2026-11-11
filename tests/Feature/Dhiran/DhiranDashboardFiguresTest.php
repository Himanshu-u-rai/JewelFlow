<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Customer;
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
 * Owner 2026-11-11: the dashboard shows the figures a pledge lender runs the day on (money lent out, total owed,
 * interest this month and this FY, overdue by age, loans due soon, gold and silver held), each linking to the list
 * behind it with the same filters, so a tile's number and the list it opens always agree.
 */
class DhiranDashboardFiguresTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_figures_and_their_links(): void
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390013000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390013000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => '9390013000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        $today = CarbonImmutable::today();
        $interestPart = 0.0;
        $html = TenantContext::runFor($shop->id, function () use ($shop, $owner, $today, &$interestPart) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $svc = app(DhiranService::class);
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'Dash', 'last_name' => 'Board', 'mobile' => '9813130001']);
            $loan = fn (float $p, string $date, int $tenure = 6, string $metal = 'gold') => $svc->createLoan($shop, $c, [[
                'description' => 'Item', 'metal_type' => $metal, 'purity' => $metal === 'silver' ? 925 : 22,
                'gross_weight' => $metal === 'silver' ? 5000 : 50, 'rate_per_gram_at_pledge' => $metal === 'silver' ? 90 : 6000,
            ]], ['principal_amount' => $p, 'gold_rate_on_date' => 6000, 'silver_rate_on_date' => 90, 'created_by' => $owner->id,
                'loan_date' => $date, 'tenure_months' => $tenure]);

            $loan(100000, $today->toDateString());                                                  // open, not due
            $loan(20000, $today->subMonthNoOverflow()->subDays(10)->toDateString(), 1);             // 10 days late
            $late60 = $loan(30000, $today->subMonthNoOverflow()->subDays(60)->toDateString(), 1);   // 60 days late
            $loan(40000, $today->subMonthNoOverflow()->addDays(5)->toDateString(), 1);              // due in 5 days
            $loan(50000, $today->toDateString(), 6, 'silver');                                     // silver
            $closed = $loan(70000, $today->toDateString());
            DB::table('dhiran_loans')->where('id', $closed->id)->update(['status' => 'closed']);  // not counted
            $svc->accrueInterest($late60->refresh());
            // An overdue loan also owes penalty, so part of the 500 goes to penalty: count only the interest part.
            $interestPart = (float) $svc->recordInterestPayment($late60->refresh(), 500, 'cash')->interest_component;

            return app(DhiranController::class)->dashboard()->render();
        });

        $fig = fn (string $key) => preg_match('/data-dash="' . preg_quote($key, '/') . '"[^>]*>\s*([^<]+?)\s*</', $html, $m) ? $m[1] : null;
        $this->assertSame('₹2,40,000', $fig('money-out'), 'principal still out on the 5 open loans, Indian grouping');
        $this->assertSame('5', $fig('open-count'));
        $this->assertGreaterThan(0, $interestPart);
        $this->assertSame('₹' . \App\Support\IndianNumber::format($interestPart), $fig('interest-month'));
        $this->assertSame('₹' . \App\Support\IndianNumber::format($interestPart), $fig('interest-fy'));
        $this->assertSame('2', $fig('overdue-count'));
        $this->assertSame('1', $fig('overdue-1-30'));
        $this->assertSame('1', $fig('overdue-31-90'));
        $this->assertSame('0', $fig('overdue-90+'));
        $this->assertSame('1', $fig('maturing-7'));
        $this->assertSame('1', $fig('maturing-30'));
        $this->assertSame('183.333 g', $fig('fine-gold'), '4 open gold loans × 50 g of 22K');
        $this->assertSame('4,625.000 g', $fig('fine-silver'));

        // Each figure opens the list behind it, with the same filter.
        foreach (['overdue=31-90', 'overdue=1-30', 'overdue=90%2B', 'maturing=7', 'maturing=30', 'status=active'] as $q) {
            $this->assertStringContainsString(e(route('dhiran.loans') . '?' . $q), $html, "links to {$q}");
        }
        $this->assertStringContainsString(e(route('dhiran.reports.interest') . '?period=this_fy'), $html);
        $this->assertStringContainsString('View all loans', $html);
    }
}

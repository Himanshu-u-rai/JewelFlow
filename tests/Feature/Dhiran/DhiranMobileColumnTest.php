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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Owner 2026-11-11: the customer's contact gets its own Mobile column (a tap-to-call link), not squeezed under the
 * name, on every Dhiran table that lists a customer.
 */
class DhiranMobileColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_with_a_customer_have_their_own_mobile_column(): void
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390011000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390011000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => '9390011000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        $pages = TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'Call', 'last_name' => 'Me', 'mobile' => '9812345678']);
            $loan = app(DhiranService::class)->createLoan($shop, $c, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 50000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id, 'loan_date' => now()->subYears(2)->toDateString()]);
            $svc = app(DhiranService::class);
            $svc->accrueInterest($loan->refresh());
            $svc->recordInterestPayment($loan->refresh(), 500, 'cash');
            $ctl = app(DhiranController::class);
            $req = fn (string $u, array $q = []) => tap(Request::create($u, 'GET', $q), fn ($r) => $this->app->instance('request', $r));

            return [
                'loans' => $ctl->loans($req('/dhiran/loans'))->render(),
                'dashboard' => $ctl->dashboard()->render(),
                'active' => $ctl->reportActive($req('/dhiran/reports/active'))->render(),
                'overdue' => $ctl->reportOverdue($req('/dhiran/reports/overdue'))->render(),
                'interest' => $ctl->reportInterest($req('/dhiran/reports/interest', ['period' => 'this_fy']))->render(),
                'profitability' => $ctl->reportProfitability($req('/dhiran/reports/profitability', ['period' => 'custom', 'from' => now()->subYears(3)->toDateString()]))->render(),
            ];
        });

        foreach ($pages as $page => $html) {
            $this->assertMatchesRegularExpression('/<th[^>]*>Mobile<\/th>/', $html, "{$page}: Mobile column");
            $this->assertMatchesRegularExpression('/<td data-label="Mobile"[^>]*>\s*<a href="tel:\+919812345678"[^>]*>98123 45678<\/a>/', $html, "{$page}: tap-to-call number");
            $customerCell = preg_match('/<td data-label="Customer"[^>]*>(.*?)<\/td>/s', $html, $m) ? $m[1] : '';
            $this->assertStringContainsString('Call Me', $customerCell, "{$page}: name in the Customer column");
            $this->assertStringNotContainsString('98123', $customerCell, "{$page}: the number is not under the name");
        }
    }
}

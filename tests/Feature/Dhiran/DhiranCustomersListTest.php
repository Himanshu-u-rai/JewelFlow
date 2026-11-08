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
 * Owner decision 2026-11-08: Dhiran's Customers list shows everyone the shop has saved, not only people with a loan,
 * with tabs All / Active loan / Fully paid / No loan yet; a customer's details can be saved without starting a loan.
 */
class DhiranCustomersListTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390002000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390002000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390002000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
    }

    private function customer(string $first, string $mobile, ?string $loanStatus = null): Customer
    {
        return TenantContext::runFor($this->shop->id, function () use ($first, $mobile, $loanStatus) {
            $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => $first, 'last_name' => 'K', 'mobile' => $mobile]);
            if ($loanStatus !== null) {
                $loan = app(DhiranService::class)->createLoan($this->shop, $c, [[
                    'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22,
                    'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
                ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id, 'status' => 'active']);
                if ($loanStatus !== 'active') {
                    \Illuminate\Support\Facades\DB::table('dhiran_loans')->where('id', $loan->id)->update(['status' => $loanStatus]);
                }
            }

            return $c;
        });
    }

    private function list(string $show = ''): string
    {
        return TenantContext::runFor($this->shop->id, function () use ($show) {
            $this->actingAs($this->owner);
            $req = Request::create('https://dhiran.jewelflows.com/dhiran/borrowers', 'GET', $show === '' ? [] : ['show' => $show]);
            $this->app->instance('request', $req);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return app(DhiranController::class)->borrowers($req)->render();
        });
    }

    public function test_customers_list_shows_everyone_and_filters_by_loan_state(): void
    {
        $this->customer('Active', '9811120001', 'active');
        $this->customer('Paidup', '9811120002', 'closed');
        $this->customer('Fresh', '9811120003');

        $all = $this->list();
        $this->assertStringContainsString('Customers', $all);
        foreach (['Active', 'Paidup', 'Fresh'] as $name) {
            $this->assertStringContainsString($name, $all);
        }
        $this->assertStringContainsString('No loan yet', $all);

        $cases = ['active' => 'Active', 'paid' => 'Paidup', 'none' => 'Fresh'];
        foreach ($cases as $show => $only) {
            $html = $this->list($show);
            foreach ($cases as $other) {
                $other === $only
                    ? $this->assertStringContainsString($other . ' K', $html, "{$show} tab misses {$other}")
                    : $this->assertStringNotContainsString($other . ' K', $html, "{$show} tab shows {$other}");
            }
        }
    }

    public function test_details_can_be_saved_without_a_loan(): void
    {
        $response = TenantContext::runFor($this->shop->id, function () {
            $this->actingAs($this->owner);
            $req = Request::create('https://dhiran.jewelflows.com/dhiran/customers', 'POST', ['first_name' => 'Walkin', 'last_name' => 'Visitor', 'mobile' => '9811120009']);
            $this->app->instance('request', $req);

            return app(DhiranController::class)->storeCustomer($req);
        });

        $customer = Customer::withoutGlobalScopes()->where('shop_id', $this->shop->id)->where('first_name', 'Walkin')->firstOrFail();
        $this->assertTrue($response->isRedirect(route('dhiran.borrowers.show', $customer)));
        $this->assertStringContainsString('Walkin Visitor', $this->list('none'));

        $form = TenantContext::runFor($this->shop->id, function () {
            $this->actingAs($this->owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return app(DhiranController::class)->createCustomer()->render();
        });
        $this->assertStringContainsString(route('dhiran.customers.store'), $form);
        $this->assertStringContainsString(route('dhiran.borrowers.create'), $this->list());
    }
}

<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Owner 2026-11-08: a loan above what the items allow was only refused after saving, with raw numbers. New Loan now
 * knows the same limits the server checks (normal LTV, the lower high-value LTV and its threshold, the shop's minimum
 * and maximum loan) and shows "Up to ₹X" under Principal, turning into a warning when the amount is outside them.
 */
class DhiranLoanLimitHintTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_loan_form_carries_the_servers_limits_and_a_live_hint(): void
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390005000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390005000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update([
            'is_enabled' => true, 'default_ltv_ratio' => 75, 'high_value_ltv_ratio' => 65,
            'high_value_threshold' => 200000, 'min_loan_amount' => 5000, 'max_loan_amount' => 900000,
        ]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => '9390005000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        $html = TenantContext::runFor($shop->id, function () use ($owner) {
            $this->actingAs($owner);
            $req = Request::create('https://dhiran.jewelflows.com/dhiran/create', 'GET');
            $this->app->instance('request', $req);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return app(DhiranController::class)->create($req)->render();
        });

        foreach (['ltvPercent: 75', 'highValueLtv: 65', 'highValueThreshold: 200000', 'minLoan: 5000', 'maxLoan: 900000'] as $seed) {
            $this->assertStringContainsString($seed, $html);
        }
        $this->assertMatchesRegularExpression('/data-principal-limit[^>]*x-text="principalHint"/', $html);
        $this->assertStringContainsString('get maxPrincipal()', $html);
    }
}

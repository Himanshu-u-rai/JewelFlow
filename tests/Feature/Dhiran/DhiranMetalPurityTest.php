<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dhiran New Pledge Loan supports gold AND silver. The form + backend must not
 * behave gold-only, and must reject unsupported metal/purity combinations.
 */
class DhiranMetalPurityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Shop,1:User} */
    private function dhiranShop(string $mobile): array
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => $mobile,
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $mobile,
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        return [$shop, $owner];
    }

    private function customer(Shop $shop, string $mobile): Customer
    {
        return TenantContext::runFor($shop->id, fn () => Customer::create([
            'shop_id' => $shop->id, 'first_name' => 'Asha', 'last_name' => 'B', 'mobile' => $mobile,
        ]));
    }

    private function submitLoan(User $owner, array $items, Customer $c, float $principal = 10000): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($owner)->post('https://dhiran.jewelflows.com/dhiran', [
            'customer_id' => $c->id, 'principal_amount' => $principal, 'gold_rate_on_date' => 6000,
            'silver_rate_on_date' => 80,
            'items' => $items,
        ]);
    }

    private function latestLoan(Shop $shop): ?DhiranLoan
    {
        return DhiranLoan::withoutGlobalScope('shop')->where('shop_id', $shop->id)->latest('id')->first();
    }

    // 1. New Pledge Loan page does not say "Gold Purity"; label is generic.
    public function test_create_page_has_no_gold_purity_label(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003000');
        $html = TenantContext::runFor($shop->id, function () use ($owner) {
            $this->actingAs($owner);
            $req = \Illuminate\Http\Request::create('https://dhiran.jewelflows.com/dhiran/create', 'GET');
            $this->app->instance('request', $req);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            return app(\App\Http\Controllers\DhiranController::class)->create($req)->render();
        });
        $this->assertStringNotContainsString('Gold Purity', $html);
        $this->assertStringNotContainsString('Purity (K)', $html);
        $this->assertStringContainsString('>Type', $html); // collateral type selector present
        // silver purity options exist in the page
        $this->assertStringContainsString('925', $html);
    }

    // 2. Gold accepts supported gold purity.
    public function test_gold_loan_accepts_gold_purity(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003001');
        $c = $this->customer($shop, '9812230001');
        $this->submitLoan($owner, [[
            'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            'description' => 'Gold chain',
        ]], $c)->assertRedirect();
        $loan = $this->latestLoan($shop);
        $this->assertNotNull($loan);
        $metal = TenantContext::runFor($shop->id, fn () => $loan->items()->first()->metal_type);
        $this->assertSame('gold', $metal);
    }

    // 3+4+5. Silver loan can be created with supported silver purity, labelled silver.
    public function test_silver_loan_created_and_labelled_silver(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003002');
        $c = $this->customer($shop, '9812230002');
        $this->submitLoan($owner, [[
            'metal_type' => 'silver', 'purity' => 925, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 80,
            'description' => 'Silver anklet',
        ]], $c, 3000)->assertRedirect();
        $loan = $this->latestLoan($shop);
        $this->assertNotNull($loan);
        $item = TenantContext::runFor($shop->id, fn () => $loan->items()->first());
        $this->assertSame('silver', $item->metal_type);          // (5) not gold
        $this->assertEqualsWithDelta(925, (float) $item->purity, 0.01);
        // silver fine weight = net * purity/1000 (millesimal), not /24
        $this->assertEqualsWithDelta(100 * 925 / 1000, (float) $item->fine_weight, 0.001);
    }

    // 6. Unsupported metal is rejected.
    public function test_unsupported_metal_rejected(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003003');
        $c = $this->customer($shop, '9812230003');
        $resp = $this->submitLoan($owner, [[
            'metal_type' => 'platinum', 'purity' => 950, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 3000,
            'description' => 'Pt ring',
        ]], $c);
        $resp->assertSessionHasErrors();
        $this->assertNull($this->latestLoan($shop));
    }

    // 7. Unsupported metal/purity combo rejected (silver with gold-only karat).
    public function test_silver_with_gold_purity_rejected(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003004');
        $c = $this->customer($shop, '9812230004');
        $resp = $this->submitLoan($owner, [[
            'metal_type' => 'silver', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 80,
            'description' => 'Silver but 22',
        ]], $c);
        $resp->assertSessionHasErrors();
        $this->assertNull($this->latestLoan($shop));
        // and gold with silver fineness
        $resp2 = $this->submitLoan($owner, [[
            'metal_type' => 'gold', 'purity' => 925, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            'description' => 'Gold but 925',
        ]], $c);
        $resp2->assertSessionHasErrors();
    }

    // 8. Missing purity is rejected (required for fine-weight).
    public function test_missing_purity_rejected(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003005');
        $c = $this->customer($shop, '9812230005');
        $resp = $this->submitLoan($owner, [[
            'metal_type' => 'gold', 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            'description' => 'No purity',
        ]], $c);
        $resp->assertSessionHasErrors();
        $this->assertNull($this->latestLoan($shop));
    }

    // Error message names the actual metal, not "gold".
    public function test_silver_error_message_names_silver(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003006');
        $c = $this->customer($shop, '9812230006');
        $resp = $this->submitLoan($owner, [[
            'metal_type' => 'silver', 'purity' => 18, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 80,
            'description' => 'Silver 18',
        ]], $c);
        $errors = session('errors');
        $this->assertNotNull($errors);
        $msg = $errors->first('items.0.purity');
        $this->assertStringContainsString('silver', $msg);
        $this->assertStringNotContainsString('gold', $msg);
    }

    // 'Other' collateral (diamond/platinum/watch) is created with a manual
    // appraised value — no purity / fine weight.
    public function test_other_collateral_created_with_appraised_value(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003007');
        $c = $this->customer($shop, '9812230007');
        $this->submitLoan($owner, [[
            'metal_type' => 'other', 'value_mode' => 'appraised', 'market_value' => 50000,
            'description' => 'Diamond ring',
        ]], $c, 20000)->assertRedirect();
        $loan = $this->latestLoan($shop);
        $this->assertNotNull($loan);
        $item = TenantContext::runFor($shop->id, fn () => $loan->items()->first());
        $this->assertSame('other', $item->metal_type);
        $this->assertEqualsWithDelta(50000, (float) $item->market_value, 0.01);
        $this->assertEqualsWithDelta(0, (float) $item->fine_weight, 0.0001); // no metal fine weight
    }

    // 'Other' without an appraised value is rejected.
    public function test_other_without_value_rejected(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003008');
        $c = $this->customer($shop, '9812230008');
        $resp = $this->submitLoan($owner, [[
            'metal_type' => 'other', 'description' => 'Watch, no value',
        ]], $c);
        $resp->assertSessionHasErrors();
        $this->assertNull($this->latestLoan($shop));
    }

    // Gold with an appraised override uses the operator's value, not metal-melt.
    public function test_gold_appraised_override_uses_manual_value(): void
    {
        [$shop, $owner] = $this->dhiranShop('9390003009');
        $c = $this->customer($shop, '9812230009');
        $this->submitLoan($owner, [[
            'metal_type' => 'gold', 'value_mode' => 'appraised', 'market_value' => 120000,
            'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            'description' => 'Antique gold (appraised above melt)',
        ]], $c, 50000)->assertRedirect();
        $loan = $this->latestLoan($shop);
        $this->assertNotNull($loan);
        $item = TenantContext::runFor($shop->id, fn () => $loan->items()->first());
        $this->assertEqualsWithDelta(120000, (float) $item->market_value, 0.01); // operator value, not fine×rate
    }
}

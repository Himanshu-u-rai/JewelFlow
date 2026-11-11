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
 * Owner 2026-11-08: on phones the profile, loan, dashboard and report tables needed a sideways swipe. They stack into
 * one card per row; each cell carries its column name (data-label) so the card can show it.
 */
class DhiranPhoneTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_body_cell_of_a_stacked_table_names_its_column(): void
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390004000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390004000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => '9390004000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        $pages = TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'Asha', 'last_name' => 'K', 'mobile' => '9814440001']);
            $svc = app(DhiranService::class);
            $loan = $svc->createLoan($shop, $c, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id, 'loan_date' => now()->subMonths(3)->toDateString()]);
            $svc->accrueInterest($loan->refresh());
            $svc->recordInterestPayment($loan->refresh(), 1000, 'cash');
            $ctl = app(DhiranController::class);
            $req = fn (string $u) => tap(Request::create($u, 'GET'), fn ($r) => $this->app->instance('request', $r));

            return [
                'profile' => $ctl->borrowerProfile(Customer::findOrFail($c->id))->render(),
                'loan' => $ctl->show($loan->refresh())->render(),
                'dashboard' => $ctl->dashboard()->render(),
                'cashbook' => $ctl->reportCashbook($req('/dhiran/reports/cashbook'))->render(),
                'active report' => $ctl->reportActive($req('/dhiran/reports/active'))->render(),
                'loans list' => $ctl->loans($req('/dhiran/loans'))->render(),
                'customers list' => $ctl->borrowers($req('/dhiran/borrowers'))->render(),
            ];
        });

        foreach ($pages as $page => $html) {
            $doc = new \DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
            $xp = new \DOMXPath($doc);
            $tables = $xp->query('//table[contains(concat(" ", normalize-space(@class), " "), " dh-stack ")]');
            $this->assertGreaterThan(0, $tables->length, "{$page}: its tables stack on phones");
            foreach ($tables as $table) {
                $heads = array_map(fn ($th) => trim(preg_replace('/\s+/', ' ', $th->textContent)), iterator_to_array($xp->query('.//thead//th', $table)));
                $rows = $xp->query('.//tbody/tr', $table);
                $this->assertGreaterThan(0, $rows->length, "{$page}: table has rows");
                foreach ($rows as $tr) {
                    $cells = $xp->query('./td', $tr);
                    if ($cells->length === 1 && $cells->item(0)->hasAttribute('colspan')) {
                        continue; // "nothing here" row
                    }
                    foreach ($cells as $i => $td) {
                        $this->assertSame($heads[$i] ?? null, $td->getAttribute('data-label'), "{$page}: cell {$i} label");
                    }
                }
            }
        }
    }
}

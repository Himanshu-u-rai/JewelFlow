<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Owner 2026-11-12: typing in a Dhiran search box must not reload the page and throw the cursor out of the box. The
 * search box sits outside a results frame (dh-results); typing refreshes only that frame, and the site-wide
 * "submit the search after 400 ms" script leaves Dhiran search boxes alone.
 */
class DhiranLiveSearchTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/dhiran/loans', '/dhiran/borrowers', '/dhiran/reports/active', '/dhiran/reports/overdue',
        '/dhiran/reports/interest', '/dhiran/reports/forfeiture', '/dhiran/reports/profitability'];

    public function test_every_dhiran_search_refreshes_only_its_results(): void
    {
        $shop = Shop::create(['name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390022000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390022000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create(['mobile_number' => '9390022000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
        TenantContext::runFor($shop->id, fn () => \App\Models\Customer::create(['shop_id' => $shop->id, 'first_name' => 'Arun', 'last_name' => 'K', 'mobile' => '9810040001']));

        foreach (self::PAGES as $path) {
            $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('https://dhiran.jewelflows.com' . $path))->assertOk()->getContent();
            $frameAt = strpos($html, '<turbo-frame id="dh-results"');
            $searchAt = strpos($html, 'data-dhiran-search');
            $this->assertNotFalse($frameAt, "{$path}: results frame");
            $this->assertNotFalse($searchAt, "{$path}: live search box");
            $this->assertLessThan($frameAt, $searchAt, "{$path}: the search box is outside the frame, so it is never replaced");
            $this->assertMatchesRegularExpression('/<turbo-frame id="dh-results"[^>]*target="_top"/', $html, "{$path}: links in the results still open full pages");
            $this->assertMatchesRegularExpression('#<turbo-frame id="dh-results".*?<table.*?</table>.*?</turbo-frame>#s', $html, "{$path}: the table is inside the frame");
            if (str_contains($html, 'data-dhiran-export')) {
                $this->assertMatchesRegularExpression('/data-dhiran-export\s+onclick="const u = new window\.URL\(window\.location\.href\)/', $html, "{$path}: export follows the live search");
            }
        }

        $app = File::get(resource_path('js/app.js'));
        $this->assertMatchesRegularExpression('/function initSearchAutoSubmit\(\)[\s\S]*?closest\(\'\[data-dhiran-search\]\'\)[\s\S]*?requestSubmit/', $app,
            'the site-wide auto-submit skips Dhiran search boxes');
        $this->assertStringContainsString("turbo:frame-render', mount", File::get(resource_path('js/list-workspace.js')), 'tables fit again after a frame refresh');
    }
}

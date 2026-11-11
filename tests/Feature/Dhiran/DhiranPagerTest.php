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
 * Owner 2026-11-08 ("where is pagination in table?"): page links only appeared past 20 rows, so a short list showed
 * nothing. Every Dhiran table now ends in a footer that always shows "Showing X–Y of Z", a rows-per-page choice
 * (20/50/100) and page controls.
 */
class DhiranPagerTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390008000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390008000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390008000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
    }

    private function loans(int $n): void
    {
        TenantContext::runFor($this->shop->id, function () use ($n) {
            $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Pager', 'last_name' => 'K', 'mobile' => '9817770001']);
            for ($i = 1; $i <= $n; $i++) {
                app(DhiranService::class)->createLoan($this->shop, $c, [[
                    'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
                ]], ['principal_amount' => 10000 + $i, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id]);
            }
        });
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

    public function test_a_short_list_still_shows_its_footer(): void
    {
        $this->loans(3);
        $html = $this->page('loans');
        $this->assertStringContainsString('data-dhiran-pager', $html);
        $this->assertMatchesRegularExpression('/Showing\s*<strong>1–3<\/strong>\s*of\s*<strong>3<\/strong>\s*loans/u', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*name="per_page"/', $html, 'rows per page');
    }

    public function test_rows_per_page_and_page_links(): void
    {
        $this->loans(23);
        $first = $this->page('loans', ['metal' => 'gold']);
        $this->assertMatchesRegularExpression('/Showing\s*<strong>1–20<\/strong>\s*of\s*<strong>23<\/strong>/u', $first);
        $this->assertStringContainsString('page=2', $first, 'next page link');
        $this->assertStringContainsString('metal=gold', substr($first, strpos($first, 'data-dhiran-pager')), 'links keep the filters');
        $this->assertMatchesRegularExpression('/<input type="hidden" name="metal" value="gold"/', $first, 'the rows-per-page form keeps the filters');

        $all = $this->page('loans', ['per_page' => 50]);
        $this->assertMatchesRegularExpression('/Showing\s*<strong>1–23<\/strong>\s*of\s*<strong>23<\/strong>/u', $all);
        $this->assertMatchesRegularExpression('/<option value="50" selected/', $all);

        // An unknown size falls back to the default instead of loading everything.
        $this->assertMatchesRegularExpression('/Showing\s*<strong>1–20<\/strong>/u', $this->page('loans', ['per_page' => 100000]));
    }

    // Owner 2026-11-11 ("where is table indexing?"): every Dhiran table starts with a # column; numbering continues
    // across pages (page 2 of 20-row pages starts at 21), so a number always means the same row.
    public function test_rows_are_numbered_and_numbering_continues_across_pages(): void
    {
        $this->loans(23);
        $page2 = $this->page('loans', ['page' => 2]);
        $table = substr($page2, strpos($page2, '<table'), strpos($page2, '</table>') - strpos($page2, '<table'));
        $this->assertMatchesRegularExpression('/<thead.*?<th[^>]*>#<\/th>/s', $table, '# heads the table');
        preg_match_all('/data-sno>(\d+)</', $table, $m);
        $this->assertSame(['21', '22', '23'], $m[1]);

        foreach (['reportActive', 'reportProfitability', 'reportCashbook', 'borrowers', 'dashboard'] as $method) {
            $html = $method === 'dashboard'
                ? TenantContext::runFor($this->shop->id, function () { $this->actingAs($this->owner); view()->share('errors', new \Illuminate\Support\ViewErrorBag); return app(DhiranController::class)->dashboard()->render(); })
                : $this->page($method);
            $this->assertMatchesRegularExpression('/<thead.*?<th[^>]*>#<\/th>/s', $html, "{$method}: # column");
            $this->assertStringContainsString('data-sno>1<', $html, "{$method}: first row is 1");
        }
    }

    public function test_reports_and_customers_use_the_same_footer(): void
    {
        $this->loans(2);
        foreach (['reportActive', 'reportProfitability', 'reportCashbook', 'borrowers'] as $method) {
            $this->assertStringContainsString('data-dhiran-pager', $this->page($method), $method);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ReorderRule;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The header bell carries the shop's notices (the low-stock rules and the
 * dashboard's warnings) on every page, so they no longer sit in the dashboard
 * body. Everyone who could read them on the dashboard still can; a row links
 * only where that person may go.
 */
class HeaderNoticeBellTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    /** Low-stock level 5; two Rings in stock against a Ring rule of 5. Returns the Ring category. */
    private function shortOfRings(Shop $shop): Category
    {
        return TenantContext::runFor($shop->id, function () use ($shop) {
            $shop->preferences()->updateOrCreate(['shop_id' => $shop->id], ['low_stock_threshold' => 5]);
            $ring = Category::create(['shop_id' => $shop->id, 'name' => 'Ring']);
            ReorderRule::create(['category' => 'Ring', 'min_stock_threshold' => 5, 'is_active' => true]);
            $this->createItem($shop->id);
            $this->createItem($shop->id);

            return $ring;
        });
    }

    public function test_the_bell_lists_the_notices_on_every_page(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $ring = $this->shortOfRings($shop);

        foreach ([route('dashboard'), route('customers.index'), route('inventory.items.index')] as $url) {
            $this->actingAs($owner)->get($url)->assertOk()
                ->assertSee('data-notice-bell', false)
                ->assertSee('data-notice-count="2"', false)
                ->assertSee('data-reorder-count="1"', false)
                ->assertSee('Low stock: only 2 item(s) in stock')
                ->assertSee('2 in stock · reorder level 5')
                ->assertSee('href="' . e(route('inventory.items.index', ['category' => $ring->id])) . '"', false)
                ->assertSee('href="' . e(route('reorder.index')) . '"', false);
        }
    }

    public function test_the_dashboard_body_no_longer_carries_the_notices(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->shortOfRings($shop);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Low Stock / Reorder Alerts')
            ->assertDontSee('dash-reorder-widget', false)
            ->assertDontSee('dash-warning-chip', false);
    }

    public function test_someone_without_stock_access_reads_the_notices_without_links(): void
    {
        [$staff, $shop] = $this->createRetailerTenant();
        $this->shortOfRings($shop);
        $this->grantOnlyPermissions($staff, []);

        $bell = $this->bell($this->actingAs($staff->fresh())->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('data-notice-count="2"', $bell);
        $this->assertStringContainsString('2 in stock · reorder level 5', $bell);
        $this->assertStringContainsString('Low stock: only 2 item(s) in stock', $bell);
        $this->assertStringNotContainsString('href=', $bell, 'a notice links where this person may not go');
    }

    /** The bell's own markup, so the page's other links do not count. */
    private function bell(string $html): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $node = (new \DOMXPath($dom))->query('//*[@data-notice-bell]')->item(0);
        $this->assertNotNull($node, 'no bell on the page');

        return $dom->saveHTML($node);
    }

    public function test_a_sub_category_rule_opens_stock_showing_just_what_it_counts(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $ring = TenantContext::runFor($shop->id, function () use ($shop) {
            $ring = Category::create(['shop_id' => $shop->id, 'name' => 'Ring']);
            ReorderRule::create(['category' => 'Ring', 'sub_category' => 'Band', 'min_stock_threshold' => 2, 'is_active' => true]);
            $this->createItem($shop->id, null, ['sub_category' => 'Band', 'design' => 'Plain band']);
            $this->createItem($shop->id, null, ['sub_category' => 'Solitaire', 'design' => 'Single stone']);

            return $ring;
        });
        $link = route('inventory.items.index', ['category' => $ring->id, 'sub_category' => 'Band']);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Ring · Band')
            ->assertSee('1 in stock · reorder level 2')
            ->assertSee('href="' . e($link) . '"', false);

        $this->actingAs($owner)->get($link)->assertOk()
            ->assertSee('Plain band')
            ->assertDontSee('Single stone');
    }

    public function test_a_shop_with_nothing_to_report_shows_a_quiet_bell(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => $shop->preferences()->updateOrCreate(['shop_id' => $shop->id], ['low_stock_threshold' => 0]));

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('data-notice-count="0"', false)
            ->assertSee('Nothing needs attention right now.');
    }
}

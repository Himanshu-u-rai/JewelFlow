<?php

namespace Tests\Feature\Navigation;

use App\Models\ReorderRule;
use App\Models\User;
use App\Services\ReorderAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The main sidebar keeps the everyday workspaces and gives the rest a home on
 * the page it belongs to: reports on the reports hub, Historical Sales in the
 * Invoices header, Installments on Customers, Categories, Tag
 * Printing and Reorder Alerts on the Stock page, Vendors and the Product
 * Catalog on the Masters hub, Data Exports in the Reports header and Import Data on Settings.
 *
 * Two rules keep a move from costing anyone a page they may open.
 *
 * 1. A new home can have a permission of its own that is not the page's: the
 *    hub wants reports.view while Close Day wants reports.daily_closing,
 *    Reports wants reports.view while Data Exports wants reports.export,
 *    Invoices wants sales.view while Historical Sales wants historical.view.
 *    A role holding only the second of a pair keeps its sidebar shortcut, and
 *    nobody who can reach the new home sees a duplicate shortcut.
 * 2. Where the new home asks for nothing the page does not (Stock, Categories,
 *    Tag Printing and Reorder Alerts share inventory.view; the Masters hub
 *    shows a card exactly when its page would open), no shortcut is needed and
 *    none is kept.
 *
 * Cash Book is not a moved page: it is a ledger people write in every day, and
 * an ordinary sidebar entry for whoever holds cash.view. Returns / Exchange
 * is likewise a permanent Sales entry for whoever holds returns.view.
 *
 * No route's authorization changed. Where a move leans on what a route asks
 * for (an edition, or a permission its new home does not ask for), the page is
 * opened as well, so the link and the route are seen to agree.
 */
class MovedShortcutsAccessTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    /** route => [the permission its page needs, the permission of the page it moved to] */
    private const MOVED = [
        'report.closing' => ['reports.daily_closing', 'reports.view'],
        'historical.index' => ['historical.view', 'sales.view'],
        'installments.index' => ['installments.view', 'customers.view'],
        'export.index' => ['reports.export', 'reports.view'],
        'imports.index' => ['imports.manage', 'settings.view'],
    ];

    /** What the sidebar listed under Reports before it kept only the hub, by edition. */
    private const REPORTS_ONCE_IN_THE_SIDEBAR = [
        'retailer' => [
            'report.closing', 'report.gst', 'report.gstr1', 'report.gstr3b', 'report.cn-register',
            'report.payment-reconciliation', 'report.day-book', 'report.inventory-valuation', 'report.dues-aging',
            'report.emi', 'report.scheme-liability', 'report.metal-liability', 'report.dead-stock',
            'report.karigar-settlement', 'report.purchase-efficiency', 'report.operator-performance',
            'report.suspicious-activity', 'report.shrinkage', 'report.metal-exchange', 'report.daily',
        ],
        'manufacturer' => [
            'report.cash', 'report.pnl', 'report.gst', 'report.gstr1', 'report.gstr3b',
            'report.cn-register', 'report.payment-reconciliation', 'report.day-book', 'report.inventory-valuation',
            'report.dead-stock', 'report.karigar-settlement', 'report.purchase-efficiency',
            'report.operator-performance', 'report.suspicious-activity', 'report.daily', 'report.closing', 'report.gold',
        ],
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_report_the_sidebar_used_to_list_is_reachable_from_the_hub(): void
    {
        foreach (self::REPORTS_ONCE_IN_THE_SIDEBAR as $edition => $routes) {
            [$owner] = $edition === 'retailer' ? $this->createRetailerTenant() : $this->createManufacturerTenant();
            $hub = $this->actingAs($owner)->get(route('report.hub'))->assertOk()->getContent();
            $sidebar = $this->sidebarNav($hub);

            foreach ($routes as $name) {
                $this->assertStringContainsString($this->href($name), $this->pageBody($hub), "{$edition}: {$name} is not on the hub");
                $this->assertStringNotContainsString($this->href($name), $sidebar, "{$edition}: {$name} is still in the sidebar");
            }
            $this->assertStringContainsString($this->href('report.hub'), $sidebar, "{$edition}: the sidebar lost its way to the hub");
        }
    }

    public function test_a_moved_shortcut_stays_in_the_sidebar_only_for_someone_who_cannot_reach_its_new_home(): void
    {
        [$user] = $this->createRetailerTenant();

        foreach (self::MOVED as $name => [$own, $home]) {
            $this->assertStringContainsString($this->href($name), $this->sidebarFor($user, [$own]),
                "{$name}: a role with {$own} but not {$home} lost its only way in");
            $this->assertStringNotContainsString($this->href($name), $this->sidebarFor($user, [$own, $home]),
                "{$name}: still in the sidebar of a role that can reach its new home");
            $this->assertStringNotContainsString($this->href($name), $this->sidebarFor($user, [$home]),
                "{$name}: offered to a role that may not open it");
        }
    }

    public function test_cash_book_is_one_ordinary_sidebar_entry_for_whoever_may_open_it(): void
    {
        [$user] = $this->createRetailerTenant();
        $cashBook = $this->href('cashbook.index');

        // With or without the reports hub: once, never twice.
        $this->assertSame(1, substr_count($this->sidebarFor($user, ['cash.view']), $cashBook));
        $this->assertSame(1, substr_count($this->sidebarFor($user, ['cash.view', 'reports.view']), $cashBook));
        $this->assertSame(0, substr_count($this->sidebarFor($user, ['reports.view']), $cashBook), 'offered to a role that may not open it');

        [$maker] = $this->createManufacturerTenant();
        $this->assertSame(1, substr_count($this->sidebarNav($this->actingAs($maker)->get(route('dashboard'))->assertOk()->getContent()), $cashBook));
    }

    public function test_master_data_left_the_sidebar_for_pages_whose_own_gate_is_no_stricter(): void
    {
        // Retailer: Categories on Stock; Vendors and Karigars on Masters.
        [$retailer] = $this->createRetailerTenant();
        $sidebar = $this->sidebarNav($this->actingAs($retailer)->get(route('dashboard'))->assertOk()->getContent());
        foreach (['categories.index', 'vendors.index', 'karigars.index'] as $name) {
            $this->assertStringNotContainsString($this->href($name), $sidebar, "retailer: {$name} is still in the sidebar");
        }
        // The least a role needs for each page is enough to find it from its new home.
        $this->assertStringContainsString($this->href('categories.index'),
            $this->stockNav($this->pageAs($retailer, ['inventory.view'], route('inventory.items.index'))));
        $this->assertStringContainsString($this->href('vendors.index'),
            $this->pageBody($this->pageAs($retailer, ['vendors.view'], route('masters.index'))));
        $karigarOnly = $this->pageAs($retailer, ['karigar.view'], route('masters.index'));
        $this->assertStringContainsString($this->href('karigars.index'), $this->pageBody($karigarOnly));
        $this->assertStringContainsString($this->href('masters.index'), $this->sidebarNav($karigarOnly));
        $this->assertStringNotContainsString($this->href('karigars.index'),
            $this->pageBody($this->pageAs($retailer, ['vendors.view'], route('masters.index'))));
        $karigarPage = $this->pageAs($retailer, ['karigar.view'], route('karigars.index'));
        $mastersLink = $this->link($this->sidebarNav($karigarPage), 'masters.index');
        $this->assertStringContainsString('active', $mastersLink);
        $this->assertStringContainsString('/karigars', $mastersLink);

        // Manufacturer: its Stock page has no view tabs, and still offers Categories; Product Catalog is a Masters card.
        [$maker] = $this->createManufacturerTenant();
        $sidebar = $this->sidebarNav($this->actingAs($maker)->get(route('dashboard'))->assertOk()->getContent());
        foreach (['categories.index', 'products.index', 'vendors.index'] as $name) {
            $this->assertStringNotContainsString($this->href($name), $sidebar, "manufacturer: {$name} is still in the sidebar");
        }
        $this->assertStringContainsString($this->href('categories.index'),
            $this->stockNav($this->pageAs($maker, ['inventory.view'], route('inventory.items.index'))));
        $this->assertStringContainsString($this->href('products.index'),
            $this->pageBody($this->pageAs($maker, ['inventory.view'], route('masters.index'))));
    }

    public function test_tag_printing_and_reorder_alerts_open_from_a_retail_shops_stock_page(): void
    {
        // Retailer: both left the sidebar for the Stock page's navigation row. The row asks for what
        // the two pages ask for, so the least role that may open them finds them there, and they open.
        [$retailer] = $this->createRetailerTenant();
        $sidebar = $this->sidebarNav($this->actingAs($retailer)->get(route('dashboard'))->assertOk()->getContent());
        $stockNav = $this->stockNav($this->pageAs($retailer, ['inventory.view'], route('inventory.items.index')));
        foreach (['tags.index', 'reorder.index'] as $name) {
            $this->assertStringNotContainsString($this->href($name), $sidebar, "retailer: {$name} is still in the sidebar");
            $this->assertStringContainsString($this->href($name), $stockNav, "retailer: {$name} is not on the Stock page");
            $this->actingAs($retailer->fresh())->get(route($name))->assertOk();
        }

        // Manufacturer: both routes are retailer-only, so its Stock page offers neither. Categories stays.
        [$maker] = $this->createManufacturerTenant();
        $stockNav = $this->stockNav($this->pageAs($maker, ['inventory.view'], route('inventory.items.index')));
        $this->assertStringContainsString($this->href('categories.index'), $stockNav);
        foreach (['tags.index', 'reorder.index'] as $name) {
            $this->assertStringNotContainsString($this->href($name), $stockNav, "manufacturer: {$name} is offered though its route refuses");
            $this->actingAs($maker->fresh())->get(route($name))->assertForbidden();
        }
    }

    /**
     * The row leads out of the Stock page; each page it opens leads back. The
     * sidebar still lights Jewellery Stock on them, but on a phone the sidebar
     * is behind the menu button, so the way back is in the page's own header,
     * as it already was on other pages that open from Stock.
     */
    public function test_each_page_that_opens_from_the_stock_row_leads_back_to_it(): void
    {
        [$retailer] = $this->createRetailerTenant();
        foreach (['categories.index', 'tags.index', 'reorder.index'] as $name) {
            $this->assertStringContainsString($this->href('inventory.items.index'),
                $this->pageHeader($this->pageAs($retailer, ['inventory.view'], route($name))), "retailer: {$name} has no way back to Stock");
        }

        // A manufacturer reaches Categories the same way, and goes back the same way.
        [$maker] = $this->createManufacturerTenant();
        $this->assertStringContainsString($this->href('inventory.items.index'),
            $this->pageHeader($this->pageAs($maker, ['inventory.view'], route('categories.index'))), 'manufacturer: Categories has no way back to Stock');
    }

    public function test_the_reorder_alert_count_moved_with_its_link_and_still_shows_on_every_page(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $count = '~>\s*2\s*</span>~';
        $anyCount = '~>\s*\d+\s*</span>~';

        // Nothing below its threshold: no count on the Stock page's link, none shown on the sidebar entry that
        // leads there (its pill stays in the page, hidden, so app.js can update it across Turbo visits).
        $this->assertDoesNotMatchRegularExpression($anyCount, $this->link(
            $this->stockNav($this->actingAs($owner)->get(route('inventory.items.index'))->assertOk()->getContent()), 'reorder.index'));
        $this->assertMatchesRegularExpression('~data-reorder-pill\s+hidden\s*>\s*0\s*</span>~', $this->link(
            $this->sidebarNav($this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent()), 'inventory.items.index'));

        // Two rules, nothing in stock for either: two alerts.
        foreach (['Ring', 'Chain'] as $category) {
            (new ReorderRule)->forceFill(['shop_id' => $shop->id, 'category' => $category, 'min_stock_threshold' => 5])->save();
        }
        app(ReorderAlertService::class)->clearCache($shop->id);

        $this->assertMatchesRegularExpression($count, $this->link(
            $this->stockNav($this->actingAs($owner)->get(route('inventory.items.index'))->assertOk()->getContent()), 'reorder.index'));
        $this->assertMatchesRegularExpression($count, $this->link(
            $this->sidebarNav($this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent()), 'inventory.items.index'));
    }

    public function test_the_new_home_offers_the_shortcut_to_a_role_that_holds_its_permission(): void
    {
        [$user] = $this->createRetailerTenant();

        // Settings keeps imports as a header action (owner, 2026-11-15: the floating button on phones and tablets);
        // exports have moved to Reports.
        $dataLinks = function (array $extra) use ($user) {
            $page = $this->pageAs($user, ['settings.view', ...$extra], route('settings.edit', ['tab' => 'general']));
            $this->assertStringNotContainsString($this->href('export.index'), $this->between($page, '<nav class="settings-nav"', '</nav>'));
            $this->assertStringNotContainsString($this->href('imports.index'), $this->between($page, '<nav class="settings-nav"', '</nav>'));

            return $this->pageHeader($page);
        };
        $this->assertStringNotContainsString($this->href('export.index'), $dataLinks(['reports.export']));
        $this->assertStringContainsString($this->href('imports.index'), $dataLinks(['imports.manage']));
        $bare = $dataLinks([]);
        $this->assertStringNotContainsString($this->href('export.index'), $bare);
        $this->assertStringNotContainsString($this->href('imports.index'), $bare);

        // Export belongs in Reports' header, never Settings, with its own gate.
        $hub = $this->pageAs($user, ['reports.view', 'reports.export'], route('report.hub'));
        $this->assertStringContainsString($this->href('export.index'), $this->pageHeader($hub));
        $this->assertStringNotContainsString($this->href('export.index'), $this->sidebarNav($hub));
        $this->assertStringNotContainsString($this->href('export.index'),
            $this->pageHeader($this->pageAs($user, ['reports.view'], route('report.hub'))));
        $export = $this->pageAs($user, ['reports.view', 'reports.export', 'settings.view'], route('export.index'));
        $this->assertStringContainsString('active', $this->link($this->sidebarNav($export), 'report.hub'));
        $this->assertStringContainsString('/export', $this->link($this->sidebarNav($export), 'report.hub'));

        // Customers: the sidebar gave up Installments because this tab is here.
        $this->assertStringContainsString('EMI / Installments',
            $this->between($this->pageAs($user, ['customers.view', 'installments.view'], route('customers.index')), 'customers-view-toggle', '</div>'));
    }

    public function test_invoices_header_and_sales_sidebar_offer_the_moved_links_by_their_own_permissions(): void
    {
        foreach (['retailer', 'manufacturer'] as $edition) {
            [$user] = $edition === 'retailer' ? $this->createRetailerTenant() : $this->createManufacturerTenant();

            // Returns / Exchange is a Sales sidebar entry, even when Invoices is accessible.
            $page = $this->pageAs($user, ['sales.view', 'returns.view'], route('invoices.index'));
            $this->assertStringContainsString($this->href('returns.index'), $this->sidebarNav($page), "{$edition}: Returns is not in the sidebar");
            $this->assertSame(1, substr_count($page, $this->href('returns.index')), "{$edition}: Returns is offered twice on one page");

            // returns.view without sales.view cannot open Invoices: the sidebar keeps the way in, and it opens.
            $this->grantOnlyPermissions($user, ['returns.view']);
            $this->actingAs($user->fresh())->get(route('invoices.index'))->assertForbidden();
            $this->assertStringContainsString($this->href('returns.index'),
                $this->sidebarNav($this->actingAs($user->fresh())->get(route('returns.index'))->assertOk()->getContent()),
                "{$edition}: a role with returns.view alone lost its only way in");

            // sales.view alone has nowhere to go from Invoices: no row of one, and nothing it may not open.
            $page = $this->pageAs($user, ['sales.view'], route('invoices.index'));
            $this->assertStringNotContainsString('invoices-page-nav', $page, "{$edition}: a navigation row with only the current page in it");
            $this->assertStringNotContainsString($this->href('returns.index'), $page, "{$edition}: Returns is offered to a role that may not open it");
            $this->assertStringNotContainsString($this->href('historical.index'), $page, "{$edition}: Historical Sales is offered to a role that may not open it");
            $this->actingAs($user->fresh())->get(route('returns.index'))->assertForbidden();

            // Historical Sales replaces Open POS in the header, not in a duplicate navigation row.
            $page = $this->pageAs($user, ['sales.view', 'historical.view'], route('invoices.index'));
            if ($edition === 'retailer') {
                $this->assertStringContainsString($this->href('historical.index'), $this->pageHeader($page));
                $this->assertSame(1, substr_count($page, $this->href('historical.index')), 'Historical Sales is offered twice on one page');
            } else {
                $this->assertStringNotContainsString($this->href('historical.index'), $page, 'a manufacturer is offered a retailer module');
                $this->actingAs($user->fresh())->get(route('historical.index'))->assertForbidden();
            }
        }
    }

    public function test_invoice_header_replaces_pos_and_returns_links_do_not_activate_invoices(): void
    {
        [$owner] = $this->createRetailerTenant();
        $html = $this->actingAs($owner)->get(route('invoices.index'))->assertOk()->getContent();
        $header = $this->pageHeader($html);
        $this->assertStringContainsString($this->href('historical.index'), $header);
        $this->assertStringNotContainsString($this->href('pos.index'), $header);
        $this->assertStringNotContainsString($this->href('returns.index'), $this->pageBody($html));
        $this->assertStringNotContainsString('invoices-page-nav', $html);
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($dom);
        $returns = $xp->query('//a[@href="'.route('returns.index').'"]/ancestor::div[contains(@class,"nav-section")][1]')->item(0);
        $this->assertNotNull($returns);
        $this->assertSame('Sales', trim($xp->evaluate('string(div[contains(@class,"nav-section-title")])', $returns)));
        $invoice = $xp->query('//a[@href="'.route('invoices.index').'"][contains(@class,"nav-link")]')->item(0);
        $this->assertStringNotContainsString('/returns', $invoice->getAttribute('data-nav-match'));
        $this->assertStringNotContainsString('/exchanges', $invoice->getAttribute('data-nav-match'));

        foreach (['returns.index', 'returns.control-center'] as $route) {
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>'.$this->get(route($route))->assertOk()->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $pageXPath = new \DOMXPath($dom);
            $active = $pageXPath->query('//*[@id="sidebar-nav"]//a[contains(concat(" ", normalize-space(@class), " "), " active ")]');
            $this->assertSame(1, $active->length, 'Only the current Sales destination should be highlighted.');
            $this->assertSame(route($route), $active->item(0)->getAttribute('href'));
        }
    }

    public function test_quick_bills_opens_entry_from_sidebar_and_register_from_form_header(): void
    {
        foreach (['retailer', 'manufacturer'] as $edition) {
            [$owner] = $edition === 'retailer' ? $this->createRetailerTenant() : $this->createManufacturerTenant();
            $page = $this->actingAs($owner)->get(route('quick-bills.create'))->assertOk()->getContent();
            $sidebar = $this->sidebarNav($page);
            $this->assertSame(1, substr_count($sidebar, $this->href('quick-bills.create')));
            $this->assertStringNotContainsString($this->href('quick-bills.index'), $sidebar);
            $this->assertStringContainsString('active', $this->link($sidebar, 'quick-bills.create'));
            $this->assertStringContainsString('data-nav-match="/quick-bills"', $this->link($sidebar, 'quick-bills.create'));
            $header = $this->pageHeader($page);
            $this->assertStringContainsString($this->href('quick-bills.index'), $header);
            $this->assertStringContainsString('Quick Bill register', $header);
            $this->assertStringContainsString('action="'.route('quick-bills.store').'"', $page);

            $register = $this->get(route('quick-bills.index'))->assertOk()->getContent();
            $this->assertStringContainsString($this->href('quick-bills.create'), $this->pageHeader($register));
            $this->assertStringContainsString('active', $this->link($this->sidebarNav($register), 'quick-bills.create'));
        }
    }

    public function test_quick_bill_view_only_staff_keep_the_register_sidebar_destination(): void
    {
        [$staff] = $this->createRetailerTenant();
        $page = $this->pageAs($staff, ['quick_bills.view'], route('quick-bills.index'));
        $sidebar = $this->sidebarNav($page);
        $this->assertStringContainsString($this->href('quick-bills.index'), $sidebar);
        $this->assertStringNotContainsString($this->href('quick-bills.create'), $sidebar);
        $this->assertStringNotContainsString($this->href('quick-bills.create'), $this->pageHeader($page));
        $this->get(route('quick-bills.create'))->assertForbidden();
    }

    public function test_quick_bill_create_only_staff_are_not_offered_the_forbidden_register(): void
    {
        [$staff] = $this->createRetailerTenant();
        $page = $this->pageAs($staff, ['quick_bills.create'], route('quick-bills.create'));
        $this->assertStringContainsString($this->href('quick-bills.create'), $this->sidebarNav($page));
        $this->assertStringNotContainsString($this->href('quick-bills.index'), $this->pageHeader($page));
        $this->get(route('quick-bills.index'))->assertForbidden();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function href(string $route): string
    {
        return 'href="'.e(route($route)).'"';
    }

    private function pageAs(User $user, array $permissions, string $url): string
    {
        $this->grantOnlyPermissions($user, $permissions);

        return $this->actingAs($user->fresh())->get($url)->assertOk()->getContent();
    }

    private function sidebarFor(User $user, array $permissions): string
    {
        return $this->sidebarNav($this->pageAs($user, $permissions, route('dashboard')));
    }

    /** The sidebar's link list, without the footer (Profile / Settings / Log out). */
    private function sidebarNav(string $html): string
    {
        return $this->between($html, 'id="sidebar-nav"', 'class="sidebar-footer"');
    }

    /** The Stock page's navigation row: its view tabs (retailer) and the pages that open from it. */
    private function stockNav(string $html): string
    {
        return $this->between($html, 'class="items-page-nav', '</nav>');
    }

    /** One link's markup, from its address to its closing tag. */
    private function link(string $html, string $route): string
    {
        return $this->between($html, $this->href($route), '</a>');
    }

    /** The bar across the top of a page: its title and its own actions. */
    private function pageHeader(string $html): string
    {
        return $this->between($this->pageBody($html), 'content-header', 'content-inner');
    }

    private function pageBody(string $html): string
    {
        return $this->between($html, 'id="main-content"', '</main>');
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "page has no {$from}");
        $end = strpos($html, $to, $start);
        $this->assertNotFalse($end, "page has no {$to} after {$from}");

        return substr($html, $start, $end - $start);
    }
}

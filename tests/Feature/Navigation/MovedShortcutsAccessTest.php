<?php

namespace Tests\Feature\Navigation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The main sidebar gave up its report links and five shortcuts: Cash Book and
 * Close Day now sit behind the reports hub, Historical Sales behind Invoices,
 * Download Reports and Import Data behind Settings.
 *
 * Each of those new homes has a permission of its own, and it is not the
 * shortcut's: the hub wants reports.view while Cash Book wants cash.view,
 * Settings wants settings.view while Download Reports wants reports.export. A
 * role holding only the second of a pair could open the page before the move
 * and would have had no way to reach it afterwards. So the sidebar keeps a
 * shortcut for exactly that person, and for nobody who can reach its new home.
 *
 * No route's authorization changed, so none is re-tested here.
 */
class MovedShortcutsAccessTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    /** route => [the permission its page needs, the permission of the page it moved to] */
    private const MOVED = [
        'cashbook.index' => ['cash.view', 'reports.view'],
        'report.closing' => ['reports.daily_closing', 'reports.view'],
        'historical.index' => ['historical.view', 'sales.view'],
        'export.index' => ['reports.export', 'settings.view'],
        'imports.index' => ['imports.manage', 'settings.view'],
    ];

    /** What the sidebar listed under Reports before it kept only the hub, by edition. */
    private const REPORTS_ONCE_IN_THE_SIDEBAR = [
        'retailer' => [
            'cashbook.index', 'report.closing', 'report.gst', 'report.gstr1', 'report.gstr3b', 'report.cn-register',
            'report.payment-reconciliation', 'report.day-book', 'report.inventory-valuation', 'report.dues-aging',
            'report.emi', 'report.scheme-liability', 'report.metal-liability', 'report.dead-stock',
            'report.karigar-settlement', 'report.purchase-efficiency', 'report.operator-performance',
            'report.suspicious-activity', 'report.shrinkage', 'report.metal-exchange', 'report.daily',
        ],
        'manufacturer' => [
            'cashbook.index', 'report.cash', 'report.pnl', 'report.gst', 'report.gstr1', 'report.gstr3b',
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

    public function test_the_new_home_offers_the_shortcut_to_a_role_that_holds_its_permission(): void
    {
        [$user] = $this->createRetailerTenant();

        // Settings: a manager reads settings without editing them, and holds both data permissions.
        $settings = fn (array $extra) => $this->between(
            $this->pageAs($user, ['settings.view', ...$extra], route('settings.edit', ['tab' => 'general'])),
            '<nav class="settings-nav">', '</nav>'
        );
        $this->assertStringContainsString($this->href('export.index'), $settings(['reports.export']));
        $this->assertStringContainsString($this->href('imports.index'), $settings(['imports.manage']));
        $bare = $settings([]);
        $this->assertStringNotContainsString($this->href('export.index'), $bare);
        $this->assertStringNotContainsString($this->href('imports.index'), $bare);

        // Invoices: the page's own action area.
        $actions = fn (array $extra) => $this->between(
            $this->pageAs($user, ['sales.view', ...$extra], route('invoices.index')),
            'invoices-page-header', 'invoices-index-page'
        );
        $this->assertStringContainsString($this->href('historical.index'), $actions(['historical.view']));
        $this->assertStringNotContainsString($this->href('historical.index'), $actions([]));

        // Historical Sales is a retailer module: a manufacturer's Invoices page has no way into it.
        [$maker] = $this->createManufacturerTenant();
        $this->assertStringNotContainsString($this->href('historical.index'),
            $this->actingAs($maker)->get(route('invoices.index'))->assertOk()->getContent());
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

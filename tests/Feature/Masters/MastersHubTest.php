<?php

namespace Tests\Feature\Masters;

use App\Models\Permission;
use App\Models\Role;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 1 — permission- and edition-aware Masters Hub (GET /masters).
 *
 * The hub is additive navigation only: every card links to an existing named
 * route and mirrors that route's own permission + edition gate, so a card is
 * shown only when the user could actually reach its destination.
 *
 * Card presence is asserted via the stable `data-masters-card="<slug>"` seam on
 * each card anchor — NOT via the destination URL, because the sidebar links to
 * the same URLs (/customers, /vendors, …) regardless of the hub's contents.
 */
class MastersHubTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function dhiranTenant(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('dhiran');
        $shop = $this->createShop('dhiran');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $this->createSubscription($shop->id, $admin, $plan);
        $this->createBillingSettings($shop->id);
        $this->markShopOpeningSetupComplete($shop->id);

        return [$user, $shop];
    }

    private function syncPermissions(int $roleId, array $names): void
    {
        $role = Role::withoutTenant()->findOrFail($roleId);
        $role->permissions()->sync(Permission::whereIn('name', $names)->pluck('id'));
    }

    private function cardSlugs(string $html): array
    {
        preg_match_all('/data-masters-card="([a-z]+)"/', $html, $m);
        sort($m[1]);

        return $m[1];
    }

    // ---- Route access ---------------------------------------------------

    public function test_authenticated_erp_owner_can_open_the_hub(): void
    {
        [$user] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->get(route('masters.index'))->assertOk()->assertSee('Masters', false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('masters.index'))->assertRedirect(route('login'));
    }

    public function test_dhiran_only_shop_is_blocked(): void
    {
        // realm defaults to ERP (unset), so realm:erp passes and the
        // edition:retailer,manufacturer gate is what refuses a dhiran-only shop.
        [$user] = $this->dhiranTenant();
        $this->actingAs($user);

        $this->get(route('masters.index'))->assertForbidden();
    }

    // ---- Edition visibility --------------------------------------------

    public function test_retailer_only_owner_sees_parties_and_categories_but_not_product_master(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertSame(['categories', 'customers', 'gst', 'karigars', 'vendors'], $slugs);
        $this->assertNotContains('products', $slugs);
    }

    public function test_manufacturer_only_owner_sees_product_master_but_not_retailer_only_parties(): void
    {
        [$user] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        // Product/Design Master + non-edition cards + Customers (retailer OR mfr).
        $this->assertSame(['categories', 'customers', 'gst', 'products'], $slugs);
        $this->assertNotContains('vendors', $slugs);   // vendors = retailer edition
        $this->assertNotContains('karigars', $slugs);  // karigars = retailer edition
    }

    public function test_multi_edition_shop_sees_both_product_master_and_retailer_parties(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        ShopEdition::grantTo($shop, ShopEdition::MANUFACTURER);
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertSame(['categories', 'customers', 'gst', 'karigars', 'products', 'vendors'], $slugs);
    }

    // ---- Permission visibility -----------------------------------------

    public function test_limited_staff_sees_only_cards_they_are_permitted(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->syncPermissions($user->role_id, ['customers.view']);
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertSame(['customers'], $slugs);
    }

    public function test_user_with_no_relevant_permissions_gets_empty_state_not_error(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->syncPermissions($user->role_id, []); // strip everything
        $this->actingAs($user);

        $response = $this->get(route('masters.index'))->assertOk();

        $this->assertSame([], $this->cardSlugs($response->getContent()));
        $response->assertSee('No master records are available', false);
    }

    // ---- Link correctness ----------------------------------------------

    public function test_cards_link_to_the_correct_existing_routes(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        ShopEdition::grantTo($shop, ShopEdition::MANUFACTURER);
        $this->actingAs($user);

        $html = $this->get(route('masters.index'))->assertOk()->getContent();

        $hrefBoundToCard = fn (string $url, string $slug) => '/href="' . preg_quote(e($url), '/')
            . '"\s+data-masters-card="' . $slug . '"/';

        // GST card is a shortcut to the existing Settings → GST & Tax tab.
        $this->assertMatchesRegularExpression($hrefBoundToCard(route('settings.edit', ['tab' => 'gst']), 'gst'), $html);
        // Categories card points at the existing Category index (not the orphan sub-category page).
        $this->assertMatchesRegularExpression($hrefBoundToCard(route('categories.index'), 'categories'), $html);
        // Product / Design Master card points at the existing products index.
        $this->assertMatchesRegularExpression($hrefBoundToCard(route('products.index'), 'products'), $html);
    }

    public function test_hub_never_renders_excluded_modules(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        ShopEdition::grantTo($shop, ShopEdition::MANUFACTURER);
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        // Only the six locked cards ever appear — no stock/items/opening-balance/
        // stone/metal/staff/payment-method/daily-rate/reorder card leaked in.
        $this->assertEqualsCanonicalizing(
            ['categories', 'customers', 'gst', 'karigars', 'products', 'vendors'],
            $slugs
        );
    }

    // ---- Navigation -----------------------------------------------------

    public function test_sidebar_shows_masters_entry_with_active_state_on_hub(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->actingAs($user);

        $html = $this->get(route('masters.index'))->assertOk()->getContent();

        // Sidebar entry present and marked active on the hub route.
        $this->assertMatchesRegularExpression(
            '/href="' . preg_quote(e(route('masters.index')), '/') . '"\s+class="nav-link active"/',
            $html
        );
    }

    // ---- Expanded permission × edition card matrix ----------------------

    public function test_inventory_only_retail_staff_sees_only_categories(): void
    {
        // inventory.view unlocks Categories (no edition gate) but NOT the
        // Product/Design Master, whose destination is manufacturer-edition only.
        [$user] = $this->createRetailerTenant();
        $this->syncPermissions($user->role_id, ['inventory.view']);
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertSame(['categories'], $slugs);
    }

    public function test_inventory_only_manufacturer_staff_sees_categories_and_products(): void
    {
        // Same permission on a manufacturer shop now clears the products edition
        // gate too, so the Design Master card appears alongside Categories.
        [$user] = $this->createManufacturerTenant();
        $this->syncPermissions($user->role_id, ['inventory.view']);
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertSame(['categories', 'products'], $slugs);
    }

    public function test_settings_only_staff_sees_only_gst(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->syncPermissions($user->role_id, ['settings.view']);
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertSame(['gst'], $slugs);
    }

    public function test_read_only_retail_shop_can_still_view_the_hub(): void
    {
        // A subscription downgrade sets access_mode=read_only, which blocks
        // writes but permits GET/HEAD (EnsureSubscriptionIsActive). The hub is
        // pure navigation, so a read-only shop still sees its full retail set.
        [$user, $shop] = $this->createRetailerTenant();
        $shop->forceFill(['access_mode' => 'read_only'])->save();
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertSame(['categories', 'customers', 'gst', 'karigars', 'vendors'], $slugs);
    }

    // ---- Card gate mirrors destination-route middleware ----------------

    public function test_each_card_gate_mirrors_its_destination_route_middleware(): void
    {
        // The hub's promise: a card only appears when the user could actually
        // reach its destination. That holds only if each card's permission +
        // edition gate is byte-identical to the destination route's own
        // `can:` and `edition:` middleware. Assert that against the live route
        // table (gatherMiddleware returns the same alias form the cards use).
        $contract = [
            // slug        => [route name,        can permission,   edition csv or null]
            'customers'  => ['customers.index',  'customers.view', 'retailer,manufacturer'],
            'vendors'    => ['vendors.index',    'vendors.view',   'retailer'],
            'karigars'   => ['karigars.index',   'karigar.view',   'retailer'],
            'categories' => ['categories.index', 'inventory.view', null],
            'products'   => ['products.index',   'inventory.view', 'manufacturer'],
            'gst'        => ['settings.edit',    'settings.view',  null],
        ];

        foreach ($contract as $slug => [$routeName, $can, $editionCsv]) {
            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Destination route {$routeName} for card {$slug} must exist.");
            $mw = $route->gatherMiddleware();

            $this->assertContains("can:{$can}", $mw, "Card {$slug} permission must match {$routeName}.");

            $editionMw = array_values(array_filter($mw, fn ($m) => str_starts_with($m, 'edition:')));
            if ($editionCsv === null) {
                $this->assertSame([], $editionMw, "Card {$slug} must have no edition gate, matching {$routeName}.");
            } else {
                $this->assertSame(["edition:{$editionCsv}"], $editionMw, "Card {$slug} edition gate must match {$routeName}.");
            }
        }
    }

    // ---- Rendered-HTML / accessibility ---------------------------------

    /** @return string[] the full <a>…</a> block for each hub card */
    private function cardBlocks(string $html): array
    {
        preg_match_all('/<a\b[^>]*data-masters-card="[a-z]+"[^>]*>.*?<\/a>/s', $html, $m);

        return $m[0];
    }

    public function test_hub_markup_is_accessible(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        ShopEdition::grantTo($shop, ShopEdition::MANUFACTURER);
        $this->actingAs($user);

        $html = $this->get(route('masters.index'))->assertOk()->getContent();

        // Exactly one document H1 (page-header supplies it; the hub adds none).
        $this->assertSame(1, substr_count($html, '<h1'), 'Page must have exactly one H1.');

        // Three section H2 headings, each with a unique, referenced id.
        foreach (['parties', 'product', 'config'] as $section) {
            $id = "masters-{$section}-heading";
            $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), "Section id {$id} must be unique.");
            $this->assertStringContainsString('aria-labelledby="' . $id . '"', $html, "Section must reference {$id}.");
        }

        $blocks = $this->cardBlocks($html);
        $this->assertCount(6, $blocks, 'Multi-edition owner should render all six cards.');

        foreach ($blocks as $block) {
            // Card is a single semantic anchor: no nested interactive controls.
            $this->assertSame(0, substr_count($block, '<button'), 'Cards must not nest buttons.');
            $this->assertSame(1, substr_count($block, '<a'), 'Cards must not nest anchors.');
            // Decorative icon hidden from the a11y tree.
            $this->assertStringContainsString('aria-hidden="true"', $block, 'Card icon must be aria-hidden.');
            // Visible keyboard-focus styling.
            $this->assertStringContainsString('focus:ring', $block, 'Card must carry a visible focus ring.');
            // No inline style attribute on the hub's own markup.
            $this->assertStringNotContainsString('style="', $block, 'Cards must not use inline styles.');
        }
    }

    // ---- Sidebar behaviour ---------------------------------------------

    public function test_sidebar_shows_masters_for_manufacturer_shop(): void
    {
        [$user] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $html = $this->get(route('masters.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="' . e(route('masters.index')) . '"', $html);
    }

    public function test_sidebar_hides_masters_for_dhiran_only_shop(): void
    {
        // Dhiran-only shops are 403'd from the hub, and the sidebar entry (gated
        // on retailer/manufacturer editions) must be absent on pages they CAN
        // reach — the dashboard renders the same sidebar.
        [$user] = $this->dhiranTenant();
        $this->actingAs($user);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('href="' . e(route('masters.index')) . '"', $html);
    }

    public function test_sidebar_preserves_existing_navigation(): void
    {
        // The additive Masters entry must not displace its siblings.
        [$user] = $this->createRetailerTenant();
        $this->actingAs($user);

        $html = $this->get(route('masters.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="' . e(route('dashboard')) . '"', $html);
        $this->assertStringContainsString('href="' . e(route('pos.index')) . '"', $html);
    }

    // ---- Responsive structure ------------------------------------------

    public function test_hub_grid_is_responsive_without_fixed_widths(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        ShopEdition::grantTo($shop, ShopEdition::MANUFACTURER);
        $this->actingAs($user);

        $html = $this->get(route('masters.index'))->assertOk()->getContent();

        // Responsive column utilities present (compiled-CSS presence is verified
        // separately in the build step; 1280px/390px pixel QA is staging work).
        $this->assertStringContainsString('grid-cols-1', $html);
        $this->assertStringContainsString('md:grid-cols-2', $html);
        $this->assertStringContainsString('lg:grid-cols-3', $html);

        // No fixed/min pixel widths on cards that could force horizontal overflow.
        foreach ($this->cardBlocks($html) as $block) {
            $this->assertDoesNotMatchRegularExpression('/\bw-\[/', $block, 'Cards must not hard-code widths.');
            $this->assertDoesNotMatchRegularExpression('/\bmin-w-\[/', $block, 'Cards must not set fixed min widths.');
        }
    }
}

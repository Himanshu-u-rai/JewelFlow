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
}

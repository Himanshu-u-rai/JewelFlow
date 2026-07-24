<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SubCategory;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 0.5 — server-side Manufacturer edition gate on Product routes.
 *
 * Product/Design Master is Manufacturer-only. Reuses the existing
 * `edition:manufacturer` middleware (EnsureShopEdition, backed by
 * Shop::hasAnyEdition()) rather than gating on shop_type, so a multi-edition
 * shop that merely holds the manufacturer entitlement is still let through.
 */
class ProductEditionGateTest extends TestCase
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

    public function test_manufacturer_shop_can_access_product_routes(): void
    {
        [$user] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.create'))->assertOk();
    }

    public function test_retailer_only_shop_cannot_direct_hit_product_routes(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->actingAs($user);

        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('products.create'))->assertForbidden();
    }

    public function test_dhiran_only_context_cannot_access_product_routes(): void
    {
        [$user] = $this->dhiranTenant();
        $this->actingAs($user);

        $this->get(route('products.index'))->assertForbidden();
    }

    public function test_multi_edition_shop_with_manufacturer_entitlement_is_allowed(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        ShopEdition::grantTo($shop, ShopEdition::MANUFACTURER);
        $this->actingAs($user);

        $this->get(route('products.index'))->assertOk();
    }

    public function test_permissions_are_still_enforced_alongside_edition_access(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        // Strip every permission from this user's role — the manufacturer edition
        // alone must not be enough; catalog.manage/inventory.view still gate it.
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        $role->permissions()->sync([]);
        $this->actingAs($user);

        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('products.create'))->assertForbidden();
    }
}

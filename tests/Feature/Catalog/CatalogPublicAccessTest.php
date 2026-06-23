<?php

namespace Tests\Feature\Catalog;

use App\Models\CatalogWebsiteSettings;
use App\Models\Item;
use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Public Catalog Website (Module 21). CatalogWebsiteHero covers hero rendering;
 * this covers the public-facing security surface: slug-scoped shop resolution
 * (cross-shop isolation), in-stock-only visibility, disabled-website + bad-slug
 * + bad-share-token 404s, and that the public storefront does not leak the
 * internal cost price. All routes are guest-accessible by design (no auth).
 */
class CatalogPublicAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** Give the shop a public storefront; returns its slug. */
    private function enableStorefront(Shop $shop, bool $enabled = true): string
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $enabled) {
            $slug = 'shop-' . $shop->id;
            $shop->forceFill(['catalog_slug' => $slug])->save();
            CatalogWebsiteSettings::updateOrCreate(
                ['shop_id' => $shop->id],
                ['is_enabled' => $enabled],
            );

            return $slug;
        });
    }

    private function publishedItem(Shop $shop, array $attrs): Item
    {
        return TenantContext::runFor($shop->id, fn () => $this->createItem($shop->id, null, array_merge([
            'status' => 'in_stock', 'category' => 'Rings',
        ], $attrs)));
    }

    // ── Visibility + isolation ──────────────────────────────────────────────

    public function test_public_products_page_renders_in_stock_item_for_guest(): void
    {
        // The public item card shows `design`, not the internal barcode.
        [, $shop] = $this->createRetailerTenant();
        $slug = $this->enableStorefront($shop);
        $this->publishedItem($shop, ['barcode' => 'PV-1', 'design' => 'PubVisibleRing']);

        $html = $this->get(self::ERP . '/s/' . $slug . '/products')->assertOk()->getContent();
        $this->assertStringContainsString('PubVisibleRing', $html, 'in-stock item is publicly listed');
    }

    public function test_sold_item_is_not_publicly_visible(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $slug = $this->enableStorefront($shop);
        $this->publishedItem($shop, ['barcode' => 'PS-1', 'design' => 'PubSoldRing', 'status' => 'sold']);

        $html = $this->get(self::ERP . '/s/' . $slug . '/products')->assertOk()->getContent();
        $this->assertStringNotContainsString('PubSoldRing', $html, 'sold item must not appear publicly');
    }

    public function test_storefront_does_not_leak_another_shops_item(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $this->enableStorefront($shopB);
        $this->publishedItem($shopB, ['barcode' => 'PB-1', 'design' => 'PubShopBSecretDesign']);

        [, $shopA] = $this->createRetailerTenant();
        $slugA = $this->enableStorefront($shopA);
        $this->publishedItem($shopA, ['barcode' => 'PA-1', 'design' => 'PubShopADesign']);

        $html = $this->get(self::ERP . '/s/' . $slugA . '/products')->assertOk()->getContent();
        $this->assertStringContainsString('PubShopADesign', $html);
        $this->assertStringNotContainsString('PubShopBSecretDesign', $html, 'another shop item must not leak into this storefront');
    }

    public function test_public_storefront_does_not_leak_internal_cost_price(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $slug = $this->enableStorefront($shop);
        // Distinctive internal cost that must never reach the public page (the
        // card shows selling_price only).
        $this->publishedItem($shop, ['barcode' => 'PC-1', 'design' => 'PubCostRing', 'cost_price' => 777771, 'selling_price' => 999991]);

        $html = $this->get(self::ERP . '/s/' . $slug . '/products')->assertOk()->getContent();
        $this->assertStringContainsString('PubCostRing', $html, 'item is listed');
        $this->assertStringNotContainsString('777771', $html, 'internal cost price must not be exposed publicly');
    }

    // ── 404s (clean, not 500) ───────────────────────────────────────────────

    public function test_unknown_slug_returns_404(): void
    {
        $this->get(self::ERP . '/s/no-such-shop/products')->assertNotFound();
    }

    public function test_disabled_storefront_returns_404(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $slug = $this->enableStorefront($shop, enabled: false); // slug set, website off
        $this->publishedItem($shop, ['barcode' => 'PUB-DISABLED-001']);

        $this->get(self::ERP . '/s/' . $slug . '/products')->assertNotFound();
    }

    public function test_unknown_share_token_is_cleanly_rejected(): void
    {
        // Unresolvable share token → a clean denial (403/404), never a 500.
        $res = $this->get(self::ERP . '/catalog/p/this-token-does-not-exist');
        $this->assertContains($res->getStatusCode(), [403, 404], 'bad share token must be a clean reject, not 500');
    }
}

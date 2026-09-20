<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ResolveCatalogShop;
use App\Models\Item;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-06 — the unauthenticated public catalog website.
 *
 * WHY THIS ROUTE GROUP GOT ITS OWN FILE
 * -------------------------------------
 * The audit has been treating "is this asset on the public disk?" as the
 * question. For item images that question has the wrong shape. `routes/web.php`
 * 94-100 serves a whole shopfront — `/s/{slug}`, its product list, its category
 * pages — with NO authentication at all, and it renders item images by public
 * URL on purpose. So item images are not an accident of a folder name; they are
 * a deliberate publication.
 *
 * I am recording that as a CORRECTION of my own framing. An earlier note in this
 * audit listed `storage/app/public/items` alongside `signatures/` and
 * `kyc/` as "candidate-public assets pending classification". Those three are
 * not alike. Signatures and KYC documents have no feature that publishes them;
 * item images have one, gated by an explicit per-shop setting.
 *
 * WHAT THE GATE ACTUALLY IS, VERIFIED
 * -----------------------------------
 * `ResolveCatalogShop` requires all three of: a shop whose `catalog_slug`
 * matches, that shop being `active()`, and `catalog_website_settings.is_enabled`
 * being true. That column is `default(false)`
 * (2026_04_01_000002_create_catalog_website_settings_table.php:14), so a shop
 * publishes nothing until someone switches it on. C-01 and C-02 pin both halves,
 * because "it is opt-in" is worth exactly as much as the test that proves a shop
 * which did not opt in gets a 404.
 *
 * THE PART THAT IS NOT A CLEAN BILL
 * ---------------------------------
 * Consent is per SHOP; publication is per ITEM. `PublicCatalogWebsiteController`
 * lists `Item::where('status', 'in_stock')` with no per-item opt-out, so
 * enabling the shopfront publishes every in-stock piece — barcode, design,
 * category, price, photo — not a chosen subset. C-03 characterizes that. It is a
 * product-consent question rather than a tenant-isolation break, and it is NOT
 * fixed here; adding a per-item flag is a feature decision, not an audit repair.
 *
 * AND THE IMAGE FILES OUTLIVE THE GATE. Turning the shopfront off makes the
 * pages 404. It does not move a single byte: the photos stay readable at
 * `/storage/items/<ULID>.webp` to anyone who already has the URL. Filenames are
 * `Str::ulid()->toBase32()` (ImageOptimizer:72,80), whose low 80 bits are random,
 * so they are not enumerable by guessing — but a URL that has been shared,
 * screenshotted or logged keeps working. Recorded as a limitation, not repaired.
 */
class PublicCatalogExposureTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    // -------------------------------------------------------------------- C-01
    /** The positive control: an enabled shopfront really does serve a guest. */
    public function test_c01_an_enabled_shopfront_serves_its_own_items_to_a_logged_out_visitor(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->publishCatalog($shop->id, 'shop-a');
        $this->stockedItem($shop->id, 'AAA-SENTINEL-1');

        $this->get('/s/shop-a/products')
            ->assertOk()
            ->assertSee('AAA-SENTINEL-1');
    }

    // -------------------------------------------------------------------- C-02
    /**
     * The gate, asserted rather than assumed. Without this, C-01 only shows that
     * the route works — not that anything stops it working for a shop that never
     * asked to be published.
     *
     * THE CONTEXT ASSERTION IS NOT PADDING. The S3-06 repair DELETED a line:
     * this branch used to call TenantContext::clear() by hand before its
     * abort(404), and now relies on the finally instead. Removing a guard
     * without pinning its replacement is how a fix quietly becomes a
     * regression, so the branch that lost the explicit clear asserts the
     * outcome the clear used to provide.
     */
    public function test_c02_a_shop_that_has_not_enabled_its_shopfront_is_not_published(): void
    {
        [, $shop] = $this->createRetailerTenant();
        // Slug assigned, catalog left switched off — the default state.
        DB::table('shops')->where('id', $shop->id)->update(['catalog_slug' => 'shop-off']);
        $this->stockedItem($shop->id, 'BBB-SENTINEL-2');

        TenantContext::clear();

        $response = $this->get('/s/shop-off/products');

        $response->assertNotFound();
        $response->assertDontSee('BBB-SENTINEL-2');
        $this->assertNull(
            TenantContext::get(),
            'S3-06: the disabled-shopfront 404 must release the context it set'
        );
    }

    // -------------------------------------------------------------------- C-03
    /**
     * CHARACTERIZATION, deliberately. Enabling the shopfront publishes every
     * in-stock item, not a chosen subset.
     *
     * This asserts the behaviour that exists. If someone later adds a per-item
     * publication flag, this test SHOULD fail — that failure is the feature
     * landing, and the assertion below is where to record it.
     */
    public function test_c03_enabling_the_shopfront_publishes_every_in_stock_item(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->publishCatalog($shop->id, 'shop-all');
        $this->stockedItem($shop->id, 'CCC-ROUTINE-3');
        $this->stockedItem($shop->id, 'CCC-HIGH-VALUE-4', ['selling_price' => 4500000]);

        $this->get('/s/shop-all/products')
            ->assertOk()
            ->assertSee('CCC-ROUTINE-3')
            ->assertSee('CCC-HIGH-VALUE-4');
    }

    // -------------------------------------------------------------------- C-04
    /**
     * Tenant isolation on a route with no authenticated user at all.
     *
     * Every other isolation test in this suite has a logged-in principal whose
     * shop_id the scope can key off. Here there is none: the tenant is chosen
     * from a URL segment supplied by an anonymous visitor. That makes it the one
     * place where a missing scope would expose another shop's inventory to the
     * open internet, so it gets its own test rather than being assumed covered.
     */
    public function test_c04_one_shops_shopfront_never_lists_another_shops_items(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $this->publishCatalog($shopA->id, 'shop-one');
        $this->publishCatalog($shopB->id, 'shop-two');

        $this->stockedItem($shopA->id, 'DDD-BELONGS-TO-A');
        $this->stockedItem($shopB->id, 'DDD-BELONGS-TO-B');

        $a = $this->get('/s/shop-one/products')->assertOk();
        $a->assertSee('DDD-BELONGS-TO-A');
        $a->assertDontSee('DDD-BELONGS-TO-B');

        $b = $this->get('/s/shop-two/products')->assertOk();
        $b->assertSee('DDD-BELONGS-TO-B');
        $b->assertDontSee('DDD-BELONGS-TO-A');
    }

    // -------------------------------------------------------------------- C-05
    /**
     * THE ACTUAL DEFECT IN THIS FILE.
     *
     * ResolveCatalogShop sets tenant context to a shop chosen by an anonymous
     * visitor's URL, then clears it after `$next($request)` returns. If `$next`
     * THROWS, the clear never runs and the request finishes its life — exception
     * handler, error view, any terminating work — still pinned to that shop.
     *
     * EnsureTenantUser, one file over, already does this correctly with
     * try/finally (EnsureTenantUser.php:31-32). The asymmetry is the finding.
     *
     * HOW BAD IS IT, HONESTLY: no cross-tenant READ is demonstrated here, and
     * there is no Octane in composer.json, so there is no worker that carries
     * the stale context into a different visitor's request. What it is, is a
     * guard that is missing on the one route group where the tenant comes from
     * an anonymous URL segment rather than from a session. That is the last
     * place to leave it off.
     */
    public function test_c05_a_throwing_handler_does_not_leave_the_visitors_tenant_context_set(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->publishCatalog($shop->id, 'shop-boom');

        Route::middleware(ResolveCatalogShop::class)
            ->get('/s/{slug}/__explode', fn () => throw new RuntimeException('handler blew up'));

        TenantContext::clear();

        // The exception must still propagate — a finally block that swallowed it
        // would be a worse bug than the one being fixed.
        $this->withoutExceptionHandling();
        try {
            $this->get('/s/shop-boom/__explode');
            $this->fail('the handler was supposed to throw');
        } catch (RuntimeException $e) {
            $this->assertSame('handler blew up', $e->getMessage());
        }

        $this->assertNull(
            TenantContext::get(),
            'S3-06: tenant context set from an anonymous URL segment must be cleared even when the handler throws'
        );
    }

    // ------------------------------------------------------------------ helpers

    private function publishCatalog(int $shopId, string $slug): void
    {
        DB::table('shops')->where('id', $shopId)->update(['catalog_slug' => $slug]);

        DB::table('catalog_website_settings')->updateOrInsert(
            ['shop_id' => $shopId],
            [
                // DB::raw('true'): PostgreSQL rejects PHP's 1 for a boolean
                // column in a bulk update (CLAUDE.md, Common Pitfalls).
                'is_enabled' => DB::raw('true'),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /** @param array<string, mixed> $overrides */
    private function stockedItem(int $shopId, string $design, array $overrides = []): Item
    {
        return $this->createItem($shopId, null, array_merge([
            'design' => $design,
            'status' => 'in_stock',
        ], $overrides));
    }
}

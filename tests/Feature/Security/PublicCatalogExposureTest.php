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

    // -------------------------------------------------------------------- C-06
    /**
     * THE IMAGE URL CARRIES NO TRACE OF THE GATE.
     *
     * `CatalogShareService::buildImageUrl` (257-273) returns
     * `{scheme}://{host}/storage/{path}` — no signature, no expiry, no shop
     * slug, no token. Nothing in the string ties it to the shopfront being on,
     * and nothing in it identifies the shop, so nothing downstream of the URL
     * can re-check the gate. This is the mechanism behind the limitation the
     * class docblock states; asserted here rather than left as prose.
     */
    public function test_c06_a_catalog_image_url_carries_no_gate_and_no_shop_identity(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $item = $this->stockedItem($shop->id, 'EEE-PHOTO-5', ['image' => 'items/01HXSENTINEL.webp']);

        $url = app(\App\Services\CatalogShareService::class)
            ->resolveImageUrl(\Illuminate\Http\Request::create('https://example.test/s/whatever'), $item);

        $this->assertSame('https://example.test/storage/items/01HXSENTINEL.webp', $url);
        $this->assertStringNotContainsString('signature', (string) $url, 'no signed-URL guard');
        $this->assertStringNotContainsString('expires', (string) $url, 'and no expiry');
        $this->assertStringNotContainsString((string) $shop->id, (string) $url,
            'and nothing identifying the shop, so no later check can re-derive the gate');
    }

    // -------------------------------------------------------------------- C-07
    /**
     * THE THREE SHOP STATES, MEASURED ON THE ONE THING THAT DIFFERS.
     *
     * Never-enabled, enabled, and enabled-then-disabled were asked about
     * separately. On the *page* they differ and C-01/C-02 already pin that. On
     * the *file* they do not differ at all, which is the answer to the question:
     * publication state has never governed where the bytes live. The upload path
     * writes to the public disk in every state
     * (`ImageOptimizer::optimizeAndStore(..., 'public')`), and flipping
     * `is_enabled` off is a row update that moves nothing.
     */
    public function test_c07_disabling_the_shopfront_closes_the_page_but_moves_no_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        [, $shop] = $this->createRetailerTenant();
        $this->publishCatalog($shop->id, 'shop-toggle');
        $this->stockedItem($shop->id, 'FFF-PHOTO-6', ['image' => 'items/01HXTOGGLE.webp']);
        \Illuminate\Support\Facades\Storage::disk('public')->put('items/01HXTOGGLE.webp', 'bytes');

        $this->get('/s/shop-toggle/products')->assertOk()->assertSee('FFF-PHOTO-6');

        DB::table('catalog_website_settings')->where('shop_id', $shop->id)
            ->update(['is_enabled' => DB::raw('false')]);
        TenantContext::clear();

        $this->get('/s/shop-toggle/products')->assertNotFound();

        \Illuminate\Support\Facades\Storage::disk('public')
            ->assertExists('items/01HXTOGGLE.webp');
    }

    // -------------------------------------------------------------------- C-08
    /**
     * THE BOUND, AND A HYPOTHESIS OF MINE THAT THE CODE REFUTED.
     *
     * While tracing direct asset URLs I found `GET /storage/{path}` in the route
     * table, named `storage.local`, with **`middleware: []`** — registered by
     * `FilesystemServiceProvider::serveFiles()` because the `local` disk sets
     * `'serve' => true`, and rooted at `storage_path('app/private')`. An
     * unauthenticated route serving the private disk would have undone the whole
     * S3-04 relocation, which moves signatures onto exactly that disk.
     *
     * It does not. `ServeFile::hasValidSignature()` treats a disk with no
     * `visibility` key as private and requires `hasValidRelativeSignature()`,
     * aborting otherwise. The `local` disk has no `visibility` key
     * (`config/filesystems.php` 5-11), so the framework default is fail-closed.
     *
     * I am recording the hypothesis alongside its refutation rather than
     * deleting it, because the difference between the two disks is the whole
     * point of this test: the public disk is reachable without a signature and
     * the private one is not, so relocation really does change something.
     *
     * THE POSITIVE CONTROL IS NOT OPTIONAL HERE. A 403 could equally mean the
     * route was never reached, which would make this test assert nothing about
     * `ServeFile` at all. The signed half proves the route serves the file when
     * the signature is right, so the unsigned 403 is attributable to the
     * signature check and not to a routing miss. Note the signature must be
     * RELATIVE — `hasValidRelativeSignature()` rejects an absolute one, and an
     * earlier probe of mine got 403 on a correctly authorized request for
     * exactly that reason.
     */
    public function test_c08_the_private_disk_serve_route_refuses_an_unsigned_anonymous_request(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('signatures/9/secret.png', 'bytes');

        $unsigned = $this->get('/storage/signatures/9/secret.png');

        $this->assertSame(403, $unsigned->getStatusCode(),
            'the local disk has no visibility key, so ServeFile requires a signed URL');
        $unsigned->assertDontSee('bytes');

        $signed = \Illuminate\Support\Facades\URL::signedRoute(
            'storage.local',
            ['path' => 'signatures/9/secret.png'],
            null,
            absolute: false
        );

        $this->get($signed)->assertOk();
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

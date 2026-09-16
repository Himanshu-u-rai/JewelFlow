<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StockPurchase;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit finding S3-03 — stock purchase invoice attachments served unauthenticated.
 *
 * THE DEFECT, AT BASELINE 018b3d8
 * -------------------------------
 * StockPurchaseController::store():127 and update():354 both write the uploaded
 * invoice to the 'public' disk under a flat 'purchases' prefix with NO shop
 * segment. Three blade sites then render Storage::url($purchase->invoice_image).
 * That URL resolves to /storage/purchases/<ulid>.<ext>, which nginx serves off
 * the public/storage symlink before any PHP runs — so no auth middleware, no
 * BelongsToShop scope and no shop_id comparison is consulted.
 *
 * The uploads are explicitly allowed to be PDFs (the file input accepts
 * application/pdf and the validator allows it), so these are real supplier
 * invoices: counterparty identity, GSTIN, line items and amounts.
 *
 * This is the same defect class as S3-02 (karigar) but had no authenticated
 * route at all, which is why containment of /storage/purchases/ would otherwise
 * have been a straight feature outage.
 *
 * THE FIX UNDER TEST
 * ------------------
 *   1. New uploads go to the PRIVATE disk under purchases/{shopId}/.
 *   2. The row records which disk holds its bytes, so the reader dispatches on
 *      recorded truth rather than a hardcoded literal. This is the property that
 *      makes the estate correct at every intermediate state of a later
 *      relocation — see RUNBOOK-S3-02.
 *   3. An authenticated, tenant-checked, permission-gated route streams them.
 *   4. Consumers emit that route, never a /storage/ URL.
 *
 * WHAT THIS FILE DOES NOT CLAIM
 * Passing tests here do NOT mean existing production files stopped being
 * exposed. The bytes already on the public tree stay there until the separately
 * approved relocation runs. This fix stops the bleeding and makes containment
 * survivable; it is not itself remediation of the existing population.
 */
class PurchaseInvoiceAttachmentTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // Without a built Vite manifest the error layout throws, turning every
        // 403/404 into a 500 and hiding the status actually under test.
        $this->withoutVite();
    }

    /**
     * Issue a request the way production resolves tenant context.
     *
     * bootstrap/app.php:83-84 runs SubstituteBindings BEFORE EnsureTenantUser, so
     * route-model binding scopes through BelongsToShop::resolveTenantShopId(),
     * whose Auth::check() fallback is gated behind runningInConsole() and returns
     * null under PHPUnit. Unpinned, every bind fail-closes to 404 and the denials
     * below would pass without the controller check ever running.
     * ProductionTenantResolutionTest covers the real resolution branch.
     */
    private function requestAs(User $user, string $url)
    {
        $this->actingAs($user);

        return TenantContext::runFor((int) $user->shop_id, fn () => $this->get($url));
    }

    /** A same-shop user on a separate role holding exactly $permissions. */
    private function staffUser(int $shopId, array $permissions): User
    {
        $role = new Role();
        $role->forceFill([
            'shop_id'      => $shopId,
            'name'         => 'staff_'.uniqid(),
            'display_name' => 'Staff',
        ])->save();
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create([
            'shop_id'   => $shopId,
            'role_id'   => $role->id,
            'is_active' => true,
        ]);
    }

    private function assertDeniedWithoutBytes($response, string $sentinel, string $who): void
    {
        $this->assertNotEquals(200, $response->status(),
            "{$who} must not receive the resource; got ".$response->status());
        $this->assertStringNotContainsString($sentinel, $response->getContent(),
            "{$who} received protected bytes in the response body");
    }

    private function purchaseWithAttachment(int $shopId, string $path, string $disk): StockPurchase
    {
        $purchase = new StockPurchase();
        $purchase->forceFill([
            'shop_id'            => $shopId,
            'supplier_name'      => 'Test Supplier',
            'purchase_number'    => 'PUR-'.uniqid(),
            'purchase_date'      => now()->toDateString(),
            'status'             => 'draft',
            'invoice_image'      => $path,
            'invoice_image_disk' => $disk,
            'total_amount'       => 0,
        ])->save();

        return $purchase;
    }

    // ============================================== the four principals ==

    /**
     * P-01 — one URL, four principals, captured from a real authorised success.
     *
     * Production change that would break this: dropping the shop_id comparison in
     * showInvoiceImage(), removing can:inventory.view from the route, or removing
     * the auth middleware.
     */
    public function test_knowing_the_purchase_invoice_url_grants_nothing_to_the_other_three_principals(): void
    {
        Storage::fake('local');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB]         = $this->createRetailerTenant();

        $path = "purchases/{$shopA->id}/invoice.pdf";
        Storage::disk('local')->put($path, 'PURCHASE_INVOICE_SENTINEL_9f1');
        $purchase = $this->purchaseWithAttachment($shopA->id, $path, 'local');

        // 1. Authorised principal — capture the URL that actually works.
        $url        = route('inventory.purchases.invoice-image', $purchase);
        $authorised = $this->requestAs($ownerA, $url);
        $authorised->assertOk();
        $this->assertSame('PURCHASE_INVOICE_SENTINEL_9f1', $authorised->streamedContent(),
            'the positive control must really return the protected bytes');

        // 2. Another shop's owner replays the identical URL.
        $this->assertDeniedWithoutBytes(
            $this->requestAs($ownerB, $url), 'PURCHASE_INVOICE_SENTINEL_9f1', 'a foreign shop owner');

        // 3. A same-shop user without inventory.view replays it.
        $staff = $this->staffUser($shopA->id, ['karigar.view']);
        $this->assertDeniedWithoutBytes(
            $this->requestAs($staff, $url), 'PURCHASE_INVOICE_SENTINEL_9f1', 'a same-shop user lacking inventory.view');

        // 4. A logged-out visitor replays it.
        app('auth')->forgetGuards();
        $this->assertDeniedWithoutBytes(
            $this->get($url), 'PURCHASE_INVOICE_SENTINEL_9f1', 'a logged-out visitor');
    }

    /**
     * P-02 — the controller must deny a foreign shop on its OWN authority.
     *
     * P-01's foreign-owner denial is confounded: BelongsToShop fail-closes the
     * route-model bind, so a controller with no shop check would still 404 and
     * P-01 would still pass. This test pins the controller's own comparison by
     * letting the bind resolve — the tenant context is deliberately set to shop A
     * while shop B's owner is authenticated.
     *
     * Without this, deleting the abort_unless() in showInvoiceImage() is a
     * surviving mutation.
     */
    public function test_the_controller_refuses_a_foreign_user_even_when_the_scope_lets_the_model_resolve(): void
    {
        Storage::fake('local');
        [, $shopA] = $this->createRetailerTenant();
        [$ownerB]  = $this->createRetailerTenant();

        $path = "purchases/{$shopA->id}/invoice.pdf";
        Storage::disk('local')->put($path, 'PURCHASE_INVOICE_SENTINEL_9f1');
        $purchase = $this->purchaseWithAttachment($shopA->id, $path, 'local');

        $url = route('inventory.purchases.invoice-image', $purchase);

        $this->actingAs($ownerB);
        $response = TenantContext::runFor($shopA->id, fn () => $this->get($url));

        $this->assertDeniedWithoutBytes($response, 'PURCHASE_INVOICE_SENTINEL_9f1',
            'a foreign user whose bind was allowed to resolve');
    }

    // =================================================== compatibility ==

    /**
     * P-03 — existing rows, whose bytes are physically on the public tree, must
     * keep downloading through the new route.
     *
     * This is the requirement that forbids a blanket switch of every row to the
     * private disk: that would strand every file already written. The reader
     * dispatches on the recorded disk instead.
     */
    public function test_a_legacy_public_disk_attachment_still_downloads_through_the_authenticated_route(): void
    {
        Storage::fake('public');
        [$ownerA, $shopA] = $this->createRetailerTenant();

        // No shop segment — exactly how baseline 018b3d8 wrote them.
        $path = 'purchases/legacy-invoice.pdf';
        Storage::disk('public')->put($path, 'LEGACY_PUBLIC_SENTINEL_2c7');
        $purchase = $this->purchaseWithAttachment($shopA->id, $path, 'public');

        $response = $this->requestAs($ownerA, route('inventory.purchases.invoice-image', $purchase));

        $response->assertOk();
        $this->assertSame('LEGACY_PUBLIC_SENTINEL_2c7', $response->streamedContent(),
            'a legacy public-disk row must remain retrievable by its own shop');
    }

    /**
     * P-04 — a foreign shop is refused a LEGACY row too.
     *
     * Containment and relocation both leave legacy rows in place for a while. If
     * authorization only held for relocated rows, the window between fix and
     * relocation would be the vulnerable one.
     */
    public function test_a_legacy_public_disk_attachment_is_still_denied_to_another_shop(): void
    {
        Storage::fake('public');
        [, $shopA] = $this->createRetailerTenant();
        [$ownerB]  = $this->createRetailerTenant();

        $path = 'purchases/legacy-invoice.pdf';
        Storage::disk('public')->put($path, 'LEGACY_PUBLIC_SENTINEL_2c7');
        $purchase = $this->purchaseWithAttachment($shopA->id, $path, 'public');

        $this->assertDeniedWithoutBytes(
            $this->requestAs($ownerB, route('inventory.purchases.invoice-image', $purchase)),
            'LEGACY_PUBLIC_SENTINEL_2c7',
            'a foreign shop owner requesting a legacy public-disk row');
    }

    // ======================================================== writers ==

    /**
     * P-05 — a NEW upload must land on the private disk, under a shop segment,
     * and must not appear on the public tree at all.
     *
     * The shop segment matters independently of the disk: the flat 'purchases'
     * prefix at baseline meant one shop's ULID collision or directory listing
     * would expose another's. Every other confidential prefix already segments
     * by shop.
     */
    public function test_a_new_upload_is_written_to_the_private_disk_under_a_shop_segment(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$ownerA, $shopA] = $this->createRetailerTenant();

        $this->actingAs($ownerA);
        TenantContext::runFor($shopA->id, function () use ($shopA) {
            $this->post(route('inventory.purchases.store'), [
                'supplier_name' => 'Acme Bullion',
                'purchase_date' => now()->toDateString(),
                'invoice_image' => UploadedFile::fake()->create('supplier-invoice.pdf', 12, 'application/pdf'),
            ]);
        });

        $purchase = StockPurchase::withoutGlobalScopes()
            ->where('shop_id', $shopA->id)
            ->whereNotNull('invoice_image')
            ->first();

        $this->assertNotNull($purchase, 'the upload must have produced a row with an attachment');
        $this->assertSame('local', $purchase->invoice_image_disk,
            'a new upload must record the private disk');
        $this->assertStringStartsWith("purchases/{$shopA->id}/", $purchase->invoice_image,
            'a new upload must be segmented by shop');
        $this->assertTrue(Storage::disk('local')->exists($purchase->invoice_image),
            'the bytes must be on the private disk');
        $this->assertFalse(Storage::disk('public')->exists($purchase->invoice_image),
            'the bytes must NOT be on the web-served public tree');
    }

    // ======================================================= consumers ==

    /**
     * P-06 — the rendered page must not emit a /storage/ URL for the attachment.
     *
     * A correct route plus a view that still links Storage::url() leaves the
     * unauthenticated path in the product. This asserts on what the consumer
     * actually emits, not on the route in isolation.
     */
    public function test_the_purchase_page_links_the_authenticated_route_and_never_a_storage_url(): void
    {
        Storage::fake('local');
        [$ownerA, $shopA] = $this->createRetailerTenant();

        $path = "purchases/{$shopA->id}/invoice.pdf";
        Storage::disk('local')->put($path, 'PURCHASE_INVOICE_SENTINEL_9f1');
        $purchase = $this->purchaseWithAttachment($shopA->id, $path, 'local');

        $response = $this->requestAs($ownerA, route('inventory.purchases.show', $purchase));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString(
            route('inventory.purchases.invoice-image', $purchase), $html,
            'the page must link the authenticated download route');
        $this->assertStringNotContainsString('/storage/purchases/', $html,
            'the page must not emit an unauthenticated /storage/ URL for the attachment');
    }

    /**
     * P-07 — a row with no attachment yields no URL and a 404 from the route,
     * rather than a 500 or an empty 200.
     */
    public function test_a_purchase_without_an_attachment_has_no_url_and_the_route_404s(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();

        $purchase = new StockPurchase();
        $purchase->forceFill([
            'shop_id'         => $shopA->id,
            'supplier_name'   => 'Test Supplier',
            'purchase_number' => 'PUR-'.uniqid(),
            'purchase_date'   => now()->toDateString(),
            'status'          => 'draft',
            'total_amount'    => 0,
        ])->save();

        $this->assertNull($purchase->invoiceImageUrl(),
            'a row with no attachment must not produce a download URL');

        $this->requestAs($ownerA, route('inventory.purchases.invoice-image', $purchase))
            ->assertNotFound();
    }

    /**
     * P-08 — the recorded disk must be constrained to the two disks that can
     * actually hold an attachment.
     *
     * Without this, a future writer recording 's3' or a typo'd disk name turns
     * every read of that row into a runtime error, and the CHECK constraint that
     * enforces both-or-neither would not catch it.
     */
    public function test_the_attachment_disk_column_rejects_an_unknown_disk(): void
    {
        [, $shopA] = $this->createRetailerTenant();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->purchaseWithAttachment($shopA->id, 'purchases/x.pdf', 'dropbox');
    }

    /**
     * P-09 — a path may never be stored without its disk, and vice versa.
     *
     * Positive control for the CHECK constraint: the both-NULL and both-NOT-NULL
     * states are legal (exercised by P-07 and P-01 respectively), so this asserts
     * only that the two half-populated states are refused.
     */
    public function test_an_attachment_path_cannot_be_stored_without_its_disk(): void
    {
        [, $shopA] = $this->createRetailerTenant();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $purchase = new StockPurchase();
        $purchase->forceFill([
            'shop_id'            => $shopA->id,
            'supplier_name'      => 'Test Supplier',
            'purchase_number'    => 'PUR-'.uniqid(),
            'purchase_date'      => now()->toDateString(),
            'status'             => 'draft',
            'invoice_image'      => 'purchases/orphan.pdf',
            'invoice_image_disk' => null,
            'total_amount'       => 0,
        ])->save();
    }
}

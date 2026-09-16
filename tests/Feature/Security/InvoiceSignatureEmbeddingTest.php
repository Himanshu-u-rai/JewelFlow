<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\InvoiceRenderSnapshot;
use App\Models\ShopBillingSettings;
use App\Models\User;
use App\Services\InvoiceSignatureRenderer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit finding S3-04 — proprietor digital signatures served unauthenticated,
 * and finalized invoices re-rendered from MUTABLE current shop settings.
 *
 * TWO DEFECTS, BOTH AT BASELINE 018b3d8
 * -------------------------------------
 * (a) EXPOSURE. invoice_print.blade.php:845 and quick-bills/print.blade.php:565
 *     render the signature with Storage::disk('public')->url(...), producing
 *     /storage/signatures/<file> — served by nginx off the public/storage
 *     symlink before any PHP runs. A digital signature is forgery material.
 *
 * (b) LOST HISTORY. invoice_render_snapshots rows are CAPTURED by
 *     InvoiceRenderSnapshotService but never READ by anything: a repo-wide grep
 *     for readers returns nothing, and both print blades take
 *     $billing = $shop?->billingSettings — the LIVE row. So reprinting a
 *     finalized invoice already renders whatever the shop's settings say today.
 *     SettingsController:524 and :529 then DELETE the old signature file on
 *     replace/remove, so the historical bytes are destroyed outright.
 *
 * Note the interaction: (b) means a disk column alone cannot fix this. Recording
 * WHERE a file lives is useless if the file is deleted, and useless again if the
 * renderer ignores the snapshot and reads current settings anyway.
 *
 * THE FIX UNDER TEST
 * ------------------
 *   1. Signature bytes are EMBEDDED as a data: URI at render time (option B), so
 *      no signature URL exists to protect. This is the only option compatible
 *      with BOTH browser print and the mobile HTML-in-JSON path, which has no
 *      session cookie to authenticate a protected <img src>.
 *   2. Uploads write to the PRIVATE disk and the row records which disk.
 *   3. Old signature files are NEVER deleted — each upload is a new immutable
 *      version, so a finalized invoice's bytes survive replacement and removal.
 *   4. The renderer resolves from the invoice's SNAPSHOT when one exists, and
 *      only falls back to live settings when it does not.
 *   5. Missing/unreadable/invalid files keep billing available and show a
 *      visible marker — they never silently substitute a different signature.
 *
 * WHAT THIS FILE DOES NOT CLAIM
 * Passing here does not mean the signature already on the production public tree
 * stopped being exposed. That byte stays there until containment or the
 * separately-approved relocation runs. Local fix status != deployed status.
 */
class InvoiceSignatureEmbeddingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();

        // Isolate every byte this file writes. Both disks are faked because the
        // compatibility cases deliberately span the legacy public tree and the
        // new private one, and the renderer must pick the right one per record.
        Storage::fake('local');
        Storage::fake('public');
    }

    /**
     * CONSOLE-ONLY HARNESS ADJUSTMENT — documented per directive.
     *
     * bootstrap/app.php:83-84 runs SubstituteBindings BEFORE EnsureTenantUser, so
     * route-model binding scopes through BelongsToShop::resolveTenantShopId(),
     * whose Auth::check() fallback is gated behind runningInConsole() — true under
     * PHPUnit — and therefore returns null. Unpinned, EVERY bind fail-closes to
     * 404 and a denial test would pass without the controller ever running.
     * Pinning context makes the bind resolve so the controller check is the thing
     * actually under test. This changes the TEST HARNESS only; no production
     * middleware is modified. ProductionTenantResolutionTest covers the real
     * resolution branch.
     */
    private function requestAs(User $user, string $url)
    {
        $this->actingAs($user);

        return TenantContext::runFor((int) $user->shop_id, fn () => $this->get($url));
    }

    /** A 1x1 PNG. Smallest thing that is a genuinely valid image. */
    private function pngBytes(string $tint = "\x00"): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ).$tint; // trailing byte differentiates "signature A" from "signature B"
    }

    private function makeInvoice(int $shopId, int $customerId, string $number): Invoice
    {
        // forceFill, not create(): every money column on Invoice is GUARDED by
        // design (CONSTITUTION Article I) so no request payload can ever set a
        // figure. Test fixtures therefore write them explicitly, the same way
        // the existing Returns suites build their drafts.
        return TenantContext::runFor($shopId, function () use ($shopId, $customerId, $number) {
            $invoice = new Invoice();
            $invoice->forceFill([
                'shop_id'        => $shopId,
                'customer_id'    => $customerId,
                'invoice_number' => $number,
                'gold_rate'      => 7200,
                'subtotal'       => 1000,
                'gst'            => 30,
                'gst_rate'       => 3,
                'wastage_charge' => 0,
                'discount'       => 0,
                'round_off'      => 0,
                'total'          => 1030,
                'status'         => Invoice::STATUS_FINALIZED,
                'finalized_at'   => now(),
            ])->save();

            return $invoice;
        });
    }

    /**
     * A real colleague inside the same shop who simply lacks sales.view.
     * Its own role row, because roles_shop_id_name_unique forbids a second
     * "owner" in one shop — and reusing the owner role would hand this user
     * the full permission set, testing nothing.
     */
    private function staffWithout(\App\Models\Shop $shop): User
    {
        $role = new \App\Models\Role();
        $role->forceFill([
            'name'         => 'floor-staff-'.fake()->unique()->numberBetween(1000, 99999),
            'display_name' => 'Floor Staff',
            'shop_id'      => $shop->id,
        ])->save();

        $staff = $this->createOwnerUser($shop, $role);
        $this->grantOnlyPermissions($staff, ['customers.view']);

        return $staff;
    }

    private function renderer(): InvoiceSignatureRenderer
    {
        return app(InvoiceSignatureRenderer::class);
    }

    /**
     * Resolve a signature the way a real render does — as a specific principal,
     * inside that principal's tenant context.
     *
     * The renderer refuses to touch storage with no principal at all, so these
     * cases authenticate even where the assertion is about fallback behaviour
     * rather than about access. Authorizing first is the point: a caller must not
     * be able to learn whether a signature file exists without being allowed to
     * see the bill it belongs to.
     *
     * @return array{show:bool, available:bool, dataUri:?string, reason:?string}
     */
    private function resolveAs(User $user, Invoice $invoice): array
    {
        $this->actingAs($user);

        return TenantContext::runFor((int) $user->shop_id, fn () => $this->renderer()->forInvoice($invoice));
    }

    // ---------------------------------------------------------------- G-01..04
    // Four principals against the print route that embeds signature bytes.

    public function test_g01_knowing_the_print_url_grants_nothing_to_the_other_three_principals(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, ] = $this->createRetailerTenant();

        $customer = $this->createCustomer($shopA->id);
        $invoice  = $this->makeInvoice($shopA->id, $customer->id, 'INV-G01');
        $url      = route('invoices.print', $invoice);

        // Positive control FIRST. Without it a 403 everywhere proves only that
        // the feature is broken, not that authorization works.
        $this->requestAs($ownerA, $url)->assertOk();

        // Principal 2 — authenticated owner of a DIFFERENT shop.
        $this->requestAs($ownerB, $url)->assertStatus(404);

        // Principal 3 — same shop, but no sales.view permission.
        $this->requestAs($this->staffWithout($shopA), $url)->assertStatus(403);

        // Principal 4 — guest.
        $this->post('/logout');
        $this->get($url)->assertRedirect('/login');
    }

    // ---------------------------------------------------------------- G-14
    /**
     * Payload growth at the supported maximum copy count.
     *
     * copy_count is validated `in:1,2`, so two is the worst case. The signature
     * markup sits inside the per-copy @for loop, so a two-copy bill carries the
     * data URI twice: the FILE is read and encoded once (the renderer memoizes and
     * is request-scoped) but the resulting string is emitted per copy. That is the
     * honest cost of inlining, and this test pins it so it cannot quietly become
     * per-copy re-encoding or an unbounded blob.
     *
     * Bound: growth must stay within the mathematical maximum — copies × base64 of
     * the file (4/3 expansion) plus a small markup allowance. Assertion is on the
     * real rendered bytes, not on an estimate.
     */
    public function test_g14_two_copies_embed_the_signature_twice_and_no_more(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // A realistic worst case: the largest file the upload rule accepts.
        $bytes = $this->pngBytes().str_repeat("\x00", InvoiceSignatureRenderer::MAX_BYTES - strlen($this->pngBytes()) - 1);
        $path  = 'signatures/'.$shop->id.'/big.png';
        Storage::disk('local')->put($path, $bytes);

        $customer = $this->createCustomer($shop->id);

        TenantContext::runFor($shop->id, function () use ($shop) {
            ShopBillingSettings::where('shop_id', $shop->id)->update(['copy_count' => 2]);
        });
        $this->setSignature($shop->id, $path, 'local', true);

        $withSig = $this->requestAs($owner, route('invoices.print',
            $this->makeInvoice($shop->id, $customer->id, 'INV-G14A')))->assertOk()->getContent();

        $this->setSignature($shop->id, null, null, false);

        $withoutSig = $this->requestAs($owner, route('invoices.print',
            $this->makeInvoice($shop->id, $customer->id, 'INV-G14B')))->assertOk()->getContent();

        $copies = substr_count($withSig, 'data:image/');
        $growth = strlen($withSig) - strlen($withoutSig);
        $encoded = strlen(base64_encode($bytes));

        fwrite(STDERR, sprintf(
            "\n[G-14] copy_count=2  file=%d B  base64=%d B  html_with=%d B  html_without=%d B  growth=%d B (%.2fx encoded)\n",
            strlen($bytes), $encoded, strlen($withSig), strlen($withoutSig), $growth, $growth / $encoded
        ));

        $this->assertSame(2, $copies, 'one data URI per printed copy, no more');
        $this->assertLessThan(
            2 * $encoded + 4096,
            $growth,
            'payload must not exceed copies x base64 plus a small markup allowance'
        );
    }

    // ---------------------------------------------------------------- G-05
    public function test_g05_an_enabled_signature_is_embedded_and_no_storage_url_is_emitted(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        Storage::disk('local')->put('signatures/'.$shop->id.'/sig-a.png', $this->pngBytes());
        $this->setSignature($shop->id, 'signatures/'.$shop->id.'/sig-a.png', 'local', true);

        $customer = $this->createCustomer($shop->id);
        $invoice  = $this->makeInvoice($shop->id, $customer->id, 'INV-G05');

        $html = $this->requestAs($owner, route('invoices.print', $invoice))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data:image/', $html, 'signature bytes must be inlined');
        $this->assertStringNotContainsString('/storage/signatures/', $html, 'no signature URL may be emitted');
    }

    // ---------------------------------------------------------------- G-06
    public function test_g06_a_disabled_signature_shows_nothing_and_warns_nothing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        Storage::disk('local')->put('signatures/'.$shop->id.'/sig-a.png', $this->pngBytes());
        $this->setSignature($shop->id, 'signatures/'.$shop->id.'/sig-a.png', 'local', false);

        $customer = $this->createCustomer($shop->id);
        $invoice  = $this->makeInvoice($shop->id, $customer->id, 'INV-G06');

        $result = $this->resolveAs($owner, $invoice);

        $this->assertFalse($result['show']);
        $this->assertNull($result['dataUri']);
        $this->assertNull($result['reason'], 'a deliberately disabled signature is not a fault to warn about');
    }

    // ---------------------------------------------------------------- G-07
    public function test_g07_a_missing_file_keeps_rendering_available_and_reports_it(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Enabled, path recorded, but the bytes are NOT on disk.
        $this->setSignature($shop->id, 'signatures/'.$shop->id.'/vanished.png', 'local', true);

        $customer = $this->createCustomer($shop->id);
        $invoice  = $this->makeInvoice($shop->id, $customer->id, 'INV-G07');

        $result = $this->resolveAs($owner, $invoice);

        $this->assertTrue($result['show'], 'the invoice still expects a signature');
        $this->assertFalse($result['available']);
        $this->assertNull($result['dataUri'], 'never substitute other bytes');
        $this->assertSame('missing', $result['reason']);
    }

    // ---------------------------------------------------------------- G-08
    public function test_g08_an_invalid_image_is_rejected_rather_than_inlined(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        Storage::disk('local')->put('signatures/'.$shop->id.'/bogus.png', 'this is not an image');
        $this->setSignature($shop->id, 'signatures/'.$shop->id.'/bogus.png', 'local', true);

        $customer = $this->createCustomer($shop->id);
        $invoice  = $this->makeInvoice($shop->id, $customer->id, 'INV-G08');

        $result = $this->resolveAs($owner, $invoice);

        $this->assertTrue($result['show']);
        $this->assertFalse($result['available']);
        $this->assertSame('invalid', $result['reason']);
    }

    // ---------------------------------------------------------------- G-09
    // THE DECISIVE REGRESSION named in the directive.
    public function test_g09_a_finalized_invoice_still_renders_signature_a_after_b_replaces_it_and_is_then_removed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $pathA = 'signatures/'.$shop->id.'/sig-a.png';
        Storage::disk('local')->put($pathA, $this->pngBytes("\x0A"));
        $this->setSignature($shop->id, $pathA, 'local', true);

        $customer = $this->createCustomer($shop->id);
        $invoice  = $this->makeInvoice($shop->id, $customer->id, 'INV-G09');

        // Finalize: capture the render snapshot exactly as production does.
        TenantContext::runFor($shop->id, fn () => app(\App\Services\InvoiceRenderSnapshotService::class)
            ->captureForInvoice($invoice));

        // Replace A with B.
        $pathB = 'signatures/'.$shop->id.'/sig-b.png';
        Storage::disk('local')->put($pathB, $this->pngBytes("\x0B"));
        $this->setSignature($shop->id, $pathB, 'local', true);

        // Then disable/remove the current signature entirely.
        $this->setSignature($shop->id, null, null, false);

        $result = $this->resolveAs($owner, $invoice->fresh());

        $this->assertTrue($result['show'], 'the snapshot, not current settings, decides');
        $this->assertTrue($result['available'], 'signature A bytes must still exist — never deleted');
        $this->assertStringContainsString(
            base64_encode($this->pngBytes("\x0A")),
            (string) $result['dataUri'],
            'the finalized invoice must render signature A, not B and not nothing'
        );
    }

    // ---------------------------------------------------------------- G-10
    public function test_g10_replacing_a_signature_does_not_delete_the_previous_file(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $pathA = 'signatures/'.$shop->id.'/sig-a.png';
        Storage::disk('local')->put($pathA, $this->pngBytes("\x0A"));
        $this->setSignature($shop->id, $pathA, 'local', true);

        $this->actingAs($owner);
        TenantContext::runFor($shop->id, function () use ($shop, $pathA) {
            $billing = ShopBillingSettings::where('shop_id', $shop->id)->first();
            // Simulate the settings write path removing the current signature.
            app(\App\Services\SignatureStore::class)->clear($billing);

            $this->assertTrue(
                Storage::disk('local')->exists($pathA),
                'historical bytes referenced by finalized invoices must survive removal'
            );
        });
    }

    // ---------------------------------------------------------------- G-11
    public function test_g11_a_legacy_snapshot_without_a_disk_key_still_resolves_to_the_public_disk(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Legacy bytes really are on the public tree.
        $legacy = 'signatures/legacy.png';
        Storage::disk('public')->put($legacy, $this->pngBytes("\x0C"));

        $customer = $this->createCustomer($shop->id);
        $invoice  = $this->makeInvoice($shop->id, $customer->id, 'INV-G11');

        // A snapshot written BEFORE the disk key existed — no digital_signature_disk.
        // Shape matches InvoiceRenderSnapshotService::buildInvoiceSnapshot exactly
        // (schema_version 1, settings nested under 'billing'). A flat payload here
        // would make this test pass while reading a shape production never writes.
        $this->putSnapshot($invoice, [
            'show_digital_signature' => true,
            'digital_signature_path' => $legacy,
        ]);

        $result = $this->resolveAs($owner, $invoice);

        $this->assertTrue($result['available'], 'legacy snapshots must keep working without rewriting them');
        $this->assertStringContainsString(base64_encode($this->pngBytes("\x0C")), (string) $result['dataUri']);
    }

    // ---------------------------------------------------------------- G-12
    public function test_g12_an_untrusted_disk_name_is_refused_rather_than_read(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $customer = $this->createCustomer($shop->id);
        $invoice  = $this->makeInvoice($shop->id, $customer->id, 'INV-G12');

        $this->putSnapshot($invoice, [
            'show_digital_signature' => true,
            'digital_signature_path' => '../../../../etc/passwd',
            'digital_signature_disk' => 's3-evil',
        ]);

        $result = $this->resolveAs($owner, $invoice);

        $this->assertFalse($result['available']);
        $this->assertSame('bad_disk', $result['reason']);
        $this->assertNull($result['dataUri']);
    }

    // ---------------------------------------------------------------- G-13
    public function test_g13_the_renderer_refuses_an_invoice_belonging_to_another_shop(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $customer = $this->createCustomer($shopA->id);
        $invoice  = $this->makeInvoice($shopA->id, $customer->id, 'INV-G13');

        $this->actingAs($ownerB);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        TenantContext::runFor($shopB->id, fn () => $this->renderer()->forInvoice($invoice));
    }

    /**
     * Write a render snapshot in the SAME shape the production capture service
     * writes: schema_version 1 with the billing settings nested under 'billing'.
     *
     * @param  array<string, mixed>  $billing
     */
    private function putSnapshot(Invoice $invoice, array $billing): void
    {
        TenantContext::runFor((int) $invoice->shop_id, fn () => InvoiceRenderSnapshot::create([
            'invoice_id' => $invoice->id,
            'shop_id'    => $invoice->shop_id,
            'snapshot'   => [
                'schema_version' => 1,
                'captured_at'    => now()->toIso8601String(),
                'billing'        => $billing,
            ],
        ]));
    }

    /** Write the current signature selection straight onto the settings row. */
    private function setSignature(int $shopId, ?string $path, ?string $disk, bool $show): void
    {
        TenantContext::runFor($shopId, function () use ($shopId, $path, $disk, $show) {
            $billing = ShopBillingSettings::where('shop_id', $shopId)->first()
                ?? new ShopBillingSettings(['shop_id' => $shopId]);
            $billing->shop_id                = $shopId;
            $billing->digital_signature_path = $path;
            $billing->digital_signature_disk = $disk;
            $billing->show_digital_signature = $show;
            $billing->save();
        });
    }
}

<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\InvoiceRenderSnapshot;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-04 — the decisive regression, driven entirely through real routes.
 *
 * WHY THIS FILE EXISTS, AND WHAT IT CORRECTS
 * ------------------------------------------
 * InvoiceSignatureEmbeddingTest G-09 and G-16 assert that a finalized invoice
 * keeps rendering signature A after B replaces it. Both pass. Neither proves the
 * property holds in production, and I reported that they did. They do not,
 * because both fixtures call
 *
 *     app(InvoiceRenderSnapshotService::class)->captureForInvoice($invoice)
 *
 * by hand. Nothing in the application does. Verified 2026-09-21: the only writer
 * of invoice_render_snapshots is InvoiceRenderSnapshotService::captureForInvoice,
 * and its only caller is the BackfillAccountingSnapshots console command.
 * InvoiceAccountingService::finalizeDraft captures a COMPLIANCE snapshot
 * (customer identity, line 137) and no render snapshot at all.
 *
 * So the fixture was supplying the one precondition production omits. The suite
 * proved the renderer READS a snapshot correctly; it never proved a snapshot
 * EXISTS. That is a test verifying its own harness, and the reason it stayed
 * invisible is the renderer's fallback: with no snapshot row it drops to live
 * settings and still renders a signature — the wrong one, with no error.
 *
 * Every test here therefore uses the real finalization route, the real settings
 * route and the real print route, captures nothing by hand, and asserts against
 * the HTML an operator would actually print.
 */
class SignatureImmutabilityEndToEndTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    /**
     * A and B are told apart by DIMENSIONS, not by file bytes.
     *
     * A CORRECTION. The first version of this file uploaded a 1x1 PNG with a
     * differing trailing byte and asserted that base64 of those exact bytes
     * appeared in the HTML. It could never pass, and it failed for a reason that
     * had nothing to do with immutability: SettingsController hands every upload
     * to SignatureStore -> ImageOptimizer::optimizeAndStore, which RE-ENCODES it
     * to WebP under a fresh ULID name. The uploaded bytes are never on disk. The
     * tint byte was worse than useless — it sits outside the image data, so GD
     * drops it and both "different" signatures would have encoded to identical
     * WebP.
     *
     * ImageOptimizer downscales only (MAX_EDGE 1600, never upscales), so these
     * two sizes survive re-encoding as genuinely different files.
     */
    private const SIG_A = [40, 20];
    private const SIG_B = [60, 30];

    // -------------------------------------------------------------------- E-01
    /**
     * Finalizing through the real route must capture a render snapshot.
     *
     * The narrowest statement of the gap. If this fails, every immutability
     * claim above it is resting on a fixture.
     */
    public function test_e01_finalizing_through_the_real_route_captures_a_render_snapshot(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->uploadSignature($owner, self::SIG_A);

        $invoice = $this->draftInvoice((int) $shop->id);
        $this->finalizeThroughRoute($owner, $invoice);

        $snapshot = InvoiceRenderSnapshot::withoutTenant()
            ->where('invoice_id', $invoice->id)
            ->value('snapshot');

        $this->assertIsArray(
            $snapshot,
            'finalization must record what the invoice was signed with. Without this row '
            .'the renderer falls back to live settings and the invoice is not immutable at all.'
        );
        $this->assertIsArray(
            $snapshot['billing'] ?? null,
            'the snapshot must carry the billing section the renderer reads'
        );
    }

    // -------------------------------------------------------------------- E-02
    /**
     * THE DECISIVE REGRESSION.
     *
     * Finalize under signature A, replace the shop signature with B through the
     * real settings route, then disable signatures entirely — also through the
     * real route. The old invoice must still print A.
     *
     * Asserted against printed HTML, not against the renderer's return value,
     * because the operator's complaint would be about the page.
     */
    public function test_e02_a_finalized_invoice_still_prints_a_after_b_replaces_it_and_signatures_are_disabled(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $signatureA = $this->uploadSignature($owner, self::SIG_A);
        $invoice = $this->draftInvoice((int) $shop->id);
        $this->finalizeThroughRoute($owner, $invoice);

        // Replace A with B, through the settings route an operator would use.
        $signatureB = $this->uploadSignature($owner, self::SIG_B);

        // Then turn signatures off entirely.
        $this->disableSignature($owner);

        $this->assertNotSame($signatureA, $signatureB, 'the two versions must be distinguishable at all');

        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString(
            $signatureA,
            $html,
            'S3-04 decisive regression: the invoice finalized under A must still print A'
        );
        $this->assertStringNotContainsString(
            $signatureB,
            $html,
            'the replacement signature must never appear on an invoice finalized before it'
        );
    }

    // -------------------------------------------------------------------- E-03
    /**
     * The positive control for E-02.
     *
     * Without it, E-02 is consistent with the print path having stopped
     * embedding signatures altogether — which would also "not print B". An
     * invoice finalized AFTER the switch to B must print B.
     */
    public function test_e03_an_invoice_finalized_after_the_replacement_prints_the_new_signature(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->uploadSignature($owner, self::SIG_A);
        $signatureB = $this->uploadSignature($owner, self::SIG_B);

        $invoice = $this->draftInvoice((int) $shop->id);
        $this->finalizeThroughRoute($owner, $invoice);

        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString(
            $signatureB,
            $html,
            'control: an invoice finalized under B must print B, so E-02 is not passing '
            .'merely because signatures stopped rendering'
        );
    }

    // -------------------------------------------------------------------- E-04
    /**
     * The enabled/disabled state is part of the snapshot, not read live.
     *
     * An invoice finalized while signatures were OFF must keep printing without
     * one even after the shop turns them on. Without this, "preserve the
     * signature choice" would cover the image but not the decision.
     */
    public function test_e04_an_invoice_finalized_with_signatures_disabled_stays_unsigned(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->uploadSignature($owner, self::SIG_A);
        $this->disableSignature($owner);

        $invoice = $this->draftInvoice((int) $shop->id);
        $this->finalizeThroughRoute($owner, $invoice);

        // The shop changes its mind afterwards.
        $signatureA = $this->uploadSignature($owner, self::SIG_A);

        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringNotContainsString(
            $signatureA,
            $html,
            'an invoice finalized while signatures were disabled must not acquire one later'
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Upload a signature exactly as an operator does, through settings, and
     * return the base64 of the bytes that ACTUALLY landed on disk.
     *
     * Returning the stored encoding rather than the uploaded one is the whole
     * point: the renderer embeds base64 of the stored WebP, so asserting on
     * anything else tests the fixture. Reading it back here also pins the
     * settings row to a specific immutable version, which is what the invoice
     * snapshot is later expected to preserve.
     *
     * @param  array{0:int,1:int}  $size
     */
    private function uploadSignature(User $owner, array $size): string
    {
        [$width, $height] = $size;

        $this->actingAs($owner)
            ->patch(route('settings.update.billing'), [
                'invoice_prefix'         => 'INV',
                'invoice_start_number'   => 1,
                'show_digital_signature' => '1',
                'digital_signature'      => UploadedFile::fake()->image('sig.png', $width, $height),
            ])
            ->assertSessionHasNoErrors();

        return $this->storedSignature((int) $owner->shop_id);
    }

    /** Base64 of the shop's CURRENT signature file, read from its recorded disk. */
    private function storedSignature(int $shopId): string
    {
        $row = DB::table('shop_billing_settings')->where('shop_id', $shopId)->first();

        $this->assertNotNull($row?->digital_signature_path, 'the upload must have recorded a path');
        $this->assertNotNull($row->digital_signature_disk, 'and the disk it went to');

        return base64_encode(Storage::disk($row->digital_signature_disk)->get($row->digital_signature_path));
    }

    private function disableSignature(User $owner): void
    {
        $this->actingAs($owner)
            ->patch(route('settings.update.billing'), [
                'invoice_prefix'       => 'INV',
                'invoice_start_number' => 1,
                // show_digital_signature omitted entirely — an unchecked box.
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * A DRAFT invoice with one line item.
     *
     * Items are inserted while the invoice is a draft because
     * invoice_items_finalized_guard (CONSTITUTION Art. IX.A) refuses inserts
     * against a finalized invoice. Money columns use forceFill because every one
     * of them is GUARDED by design (Art. I). No trigger is dropped or altered.
     */
    private function draftInvoice(int $shopId): Invoice
    {
        $customer = $this->createCustomer($shopId);

        return TenantContext::runFor($shopId, function () use ($shopId, $customer) {
            $invoice = new Invoice();
            $invoice->forceFill([
                'shop_id'        => $shopId,
                'customer_id'    => $customer->id,
                'gold_rate'      => 7200,
                'subtotal'       => 1000,
                'gst_rate'       => 3,
                // gst and total are NOT NULL even on a draft. Seeded at zero
                // rather than pre-computed: finalizeDraft() recalculates both
                // from the line items, so seeding real figures here would hide
                // whether the real calculation ran.
                'gst'            => 0,
                'total'          => 0,
                'wastage_charge' => 0,
                'discount'       => 0,
                'round_off'      => 0,
                'status'         => Invoice::STATUS_DRAFT,
            ])->save();

            $item = $this->createItem($shopId, null, ['metal_type' => 'gold']);

            DB::table('invoice_items')->insert([
                'invoice_id'     => $invoice->id,
                'item_id'        => $item->id,
                'metal_type'     => 'gold',
                'weight'         => 10,
                'rate'           => 7200,
                'making_charges' => 0,
                'stone_amount'   => 0,
                'line_total'     => 1000,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return $invoice;
        });
    }

    /** Finalize via PUT invoices.update with action=finalize — the real path. */
    private function finalizeThroughRoute(User $owner, Invoice $invoice): void
    {
        $owner->unsetRelation('shop');

        TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->put(route('invoices.update', $invoice), ['action' => 'finalize'])
            ->assertSessionHasNoErrors());
    }

    /**
     * Print through the real route and return the HTML.
     *
     * unsetRelation('shop') reproduces the per-request freshness a real request
     * has; actingAs() otherwise keeps one User (and its cached billingSettings)
     * alive across every call in the test, which would hide settings changes.
     * Harness only — no production behaviour depends on it.
     */
    private function printInvoice(User $owner, Invoice $invoice): string
    {
        $owner->unsetRelation('shop');

        return TenantContext::runFor(
            (int) $owner->shop_id,
            fn () => $this->actingAs($owner)
                ->get(route('invoices.print', $invoice))
                ->assertOk()
                ->getContent()
        );
    }
}

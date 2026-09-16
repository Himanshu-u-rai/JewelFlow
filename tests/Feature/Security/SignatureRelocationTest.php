<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\InvoiceRenderSnapshot;
use App\Models\ShopBillingSettings;
use App\Services\InvoiceSignatureRenderer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-04 relocation safety contract — signatures:relocate.
 *
 * HONEST PROVENANCE
 * RelocateShopSignatures was written before these tests, modelled on the
 * existing RelocateKarigarInvoiceAttachments. So the tests below passed on
 * first run and their red phase proves nothing on its own. Each safety clause
 * is therefore additionally established by a recorded mutation (see the
 * handoff): a test whose guard is removed must die, or the clause is not
 * actually pinned by anything.
 *
 * The clauses mirror the karigar command's R-numbers where they correspond.
 */
class SignatureRelocationTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    /** A 1x1 PNG whose bytes differ per tint, so copies are distinguishable. */
    private function pngBytes(string $tint = "\x00"): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ).$tint;
    }

    /** Put a signature on the public tree and record it there, as at baseline. */
    private function legacySignature(int $shopId, string $tint = "\x0A"): string
    {
        $path = 'signatures/'.$shopId.'/sig-legacy.png';

        Storage::disk('public')->put($path, $this->pngBytes($tint));

        $billing = ShopBillingSettings::withoutTenant()->where('shop_id', $shopId)->first()
            ?? ShopBillingSettings::withoutTenant()->create(['shop_id' => $shopId]);

        DB::table('shop_billing_settings')->where('id', $billing->id)->update([
            'digital_signature_path'   => $path,
            'digital_signature_disk'   => 'public',
            // DB::raw('true'): PostgreSQL rejects PHP's 1 for a boolean column
            // in a bulk update (CLAUDE.md, Common Pitfalls).
            'show_digital_signature'   => DB::raw('true'),
        ]);

        return $path;
    }

    // ------------------------------------------------------------------ R-01
    public function test_r01_the_default_run_is_a_dry_run_and_moves_nothing(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $path = $this->legacySignature($shop->id);

        $this->artisan('signatures:relocate')->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists($path), 'a dry run must not copy bytes');
        $this->assertSame('public', DB::table('shop_billing_settings')
            ->where('shop_id', $shop->id)->value('digital_signature_disk'),
            'a dry run must not flip the recorded disk');
    }

    // ------------------------------------------------------------------ R-03
    public function test_r03_execute_copies_the_bytes_and_flips_the_recorded_disk(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $path = $this->legacySignature($shop->id);

        $this->artisan('signatures:relocate --execute')->assertExitCode(0);

        $this->assertSame($this->pngBytes("\x0A"), Storage::disk('local')->get($path));
        $this->assertSame('local', DB::table('shop_billing_settings')
            ->where('shop_id', $shop->id)->value('digital_signature_disk'));
    }

    // ------------------------------------------------------------------ R-04
    public function test_r04_the_public_original_survives_relocation(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $path = $this->legacySignature($shop->id);

        $this->artisan('signatures:relocate --execute')->assertExitCode(0);

        $this->assertTrue(
            Storage::disk('public')->exists($path),
            'relocation must not delete originals; purging is a separate gated pass'
        );
    }

    // ------------------------------------------------------------------ R-06
    public function test_r06_a_row_whose_source_is_missing_keeps_its_recorded_disk(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $path = $this->legacySignature($shop->id);
        Storage::disk('public')->delete($path);

        $this->artisan('signatures:relocate --execute')->assertExitCode(1);

        $this->assertSame('public', DB::table('shop_billing_settings')
            ->where('shop_id', $shop->id)->value('digital_signature_disk'),
            'failing closed here means changing nothing, not repointing at a file that is not there');
    }

    // ------------------------------------------------------------------ R-08
    public function test_r08_running_twice_is_a_no_op(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->legacySignature($shop->id);

        $this->artisan('signatures:relocate --execute')->assertExitCode(0);
        $this->artisan('signatures:relocate --execute')->assertExitCode(0)
            ->expectsOutputToContain('0 relocated');
    }

    // ------------------------------------------------------------------ R-12
    public function test_r12_purge_refuses_wholesale_when_any_private_copy_is_missing(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $pathA = $this->legacySignature($shopA->id, "\x0A");
        $pathB = $this->legacySignature($shopB->id, "\x0B");

        $this->artisan('signatures:relocate --execute')->assertExitCode(0);

        // Something removed one private copy after the fact.
        Storage::disk('local')->delete($pathB);

        $this->artisan('signatures:relocate --purge-originals --execute')->assertExitCode(1);

        $this->assertTrue(
            Storage::disk('public')->exists($pathA),
            'a partially trustworthy run must not delete the good copies either'
        );
        $this->assertTrue(Storage::disk('public')->exists($pathB));
    }

    // ------------------------------------------------------------------ R-14
    /**
     * The clause that does not exist in the karigar command: a purged signature
     * must still render for an invoice whose immutable snapshot names the old
     * disk. This is the end-to-end demonstration that the relocation mechanism
     * preserves signature A.
     */
    public function test_r14_a_finalized_invoice_still_renders_after_relocation_and_purge(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $path = $this->legacySignature($shop->id, "\x0A");

        $customer = $this->createCustomer($shop->id);

        $invoice = TenantContext::runFor($shop->id, function () use ($shop, $customer) {
            $invoice = new Invoice();
            $invoice->forceFill([
                'shop_id' => $shop->id, 'customer_id' => $customer->id,
                'invoice_number' => 'INV-R14', 'gold_rate' => 7200,
                'subtotal' => 1000, 'gst' => 30, 'gst_rate' => 3,
                'wastage_charge' => 0, 'discount' => 0, 'round_off' => 0,
                'total' => 1030, 'status' => Invoice::STATUS_FINALIZED,
                'finalized_at' => now(),
            ])->save();

            return $invoice;
        });

        // Finalized while the signature was still on the public tree.
        TenantContext::runFor($shop->id, fn () => app(\App\Services\InvoiceRenderSnapshotService::class)
            ->captureForInvoice($invoice));

        $snapshot = InvoiceRenderSnapshot::withoutTenant()->where('invoice_id', $invoice->id)->value('snapshot');
        $this->assertSame('public', $snapshot['billing']['digital_signature_disk'] ?? null);

        $this->artisan('signatures:relocate --execute')->assertExitCode(0);
        $this->artisan('signatures:relocate --purge-originals --execute')->assertExitCode(0);

        $this->assertFalse(Storage::disk('public')->exists($path), 'the public exposure is gone');

        // The snapshot is byte-identical: relocation rewrites no finalized row.
        $after = InvoiceRenderSnapshot::withoutTenant()->where('invoice_id', $invoice->id)->value('snapshot');
        $this->assertSame($snapshot, $after, 'a finalized snapshot must never be rewritten');

        $this->actingAs($owner);
        $result = TenantContext::runFor($shop->id, fn () => app(InvoiceSignatureRenderer::class)
            ->forInvoice($invoice->fresh()));

        $this->assertTrue($result['available'], 'signature A must survive relocation AND purge');
        $this->assertStringContainsString(base64_encode($this->pngBytes("\x0A")), (string) $result['dataUri']);
    }

    // ------------------------------------------------------------------ R-16
    /** Superseded versions are reported, never moved — they have no row to repoint. */
    public function test_r16_superseded_versions_are_reported_and_left_alone(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->legacySignature($shop->id);

        $orphan = 'signatures/'.$shop->id.'/sig-superseded.png';
        Storage::disk('public')->put($orphan, $this->pngBytes("\x0C"));

        $this->artisan('signatures:relocate --orphans')
            ->expectsOutputToContain('sig-superseded.png')
            ->assertExitCode(0);

        $this->assertTrue(Storage::disk('public')->exists($orphan), 'reported, not moved');
        $this->assertFalse(Storage::disk('local')->exists($orphan));
    }

    // ------------------------------------------------------------------ R-09
    public function test_r09_shop_scope_bounds_the_blast_radius(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $this->legacySignature($shopA->id, "\x0A");
        $pathB = $this->legacySignature($shopB->id, "\x0B");

        $this->artisan('signatures:relocate --execute --shop='.$shopA->id)->assertExitCode(0);

        $this->assertSame('local', DB::table('shop_billing_settings')
            ->where('shop_id', $shopA->id)->value('digital_signature_disk'));
        $this->assertSame('public', DB::table('shop_billing_settings')
            ->where('shop_id', $shopB->id)->value('digital_signature_disk'));
        $this->assertFalse(Storage::disk('local')->exists($pathB));
    }
}

<?php

namespace Tests\Feature\Security;

use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\ShopBillingSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * XR-03 (S3-02 / S3-03 / S3-04 relocation) — the movers must never destroy a
 * file that was already at the destination.
 *
 * Found by review, confirmed in source at 7b1d709. All three movers
 * (karigar-invoices:relocate-attachments, purchases:relocate-invoice-images,
 * signatures:relocate) wrote straight over the destination path, their
 * failed-copy cleanup deleted that path, and the signature mover checked that
 * a path belongs to the shop only inside the ledger write — after copying.
 *
 * The contract now: validate first; then an ABSENT destination is published
 * without clobbering, an IDENTICAL one is resumed without rewriting, and a
 * CONFLICTING one is refused with both files, the row and the ledger
 * untouched. A mover deletes only the temporary file it created.
 *
 * Every case runs the commands themselves.
 */
class RelocationNonDestructiveTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function karigarInvoiceOnPublicDisk(string $bytes): KarigarInvoice
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $shopId = (int) $shop->id;
        $karigar = TenantContext::runFor($shopId, fn () => Karigar::create([
            'shop_id' => $shopId, 'name' => 'XR-03 Karigar', 'mobile' => '98'.random_int(10000000, 99999999),
        ]));
        $path = "karigar-invoices/{$shopId}/".uniqid().'.pdf';
        Storage::disk('public')->put($path, $bytes);

        return TenantContext::runFor($shopId, fn () => KarigarInvoice::create([
            'shop_id' => $shopId, 'karigar_id' => $karigar->id, 'mode' => KarigarInvoice::MODE_PURCHASE,
            'karigar_invoice_number' => 'KI-'.uniqid(), 'karigar_invoice_date' => now()->toDateString(),
            'payment_status' => KarigarInvoice::PAYMENT_UNPAID, 'amount_paid' => 0,
            'invoice_file_path' => $path, 'invoice_file_disk' => 'public', 'created_by_user_id' => $owner->id,
        ]));
    }

    private function karigarDisk(KarigarInvoice $invoice): ?string
    {
        return DB::table('karigar_invoices')->where('id', $invoice->id)->value('invoice_file_disk');
    }

    private function manifest(): string
    {
        return collect(Storage::disk('local')->files('relocation-manifests'))
            ->map(fn ($f) => Storage::disk('local')->get($f))->implode("\n");
    }

    /** Signature for $shopId whose recorded path is $path, bytes on the public disk. */
    private function publicSignature(int $shopId, string $path, string $bytes): void
    {
        Storage::disk('public')->put($path, $bytes);
        $billing = ShopBillingSettings::withoutTenant()->where('shop_id', $shopId)->first();
        DB::table('shop_billing_settings')->where('id', $billing->id)->update([
            'digital_signature_path' => $path, 'digital_signature_disk' => 'public', 'show_digital_signature' => DB::raw('true'),
        ]);
    }

    private function signatureRow(int $shopId): object
    {
        return DB::table('shop_billing_settings')->where('shop_id', $shopId)->first();
    }

    // ────────────────────────────────────────────────────────────────────
    // Karigar (and, by shared code, purchase) mover
    // ────────────────────────────────────────────────────────────────────

    public function test_a_conflicting_destination_is_refused_and_both_files_survive(): void
    {
        $invoice = $this->karigarInvoiceOnPublicDisk('PUBLIC_ORIGINAL');
        Storage::disk('local')->put($invoice->invoice_file_path, 'PRIVATE_DIFFERENT');

        $this->artisan('karigar-invoices:relocate-attachments', ['--execute' => true])->assertExitCode(1);

        $this->assertSame('PRIVATE_DIFFERENT', Storage::disk('local')->get($invoice->invoice_file_path), 'the existing file is not overwritten');
        $this->assertSame('PUBLIC_ORIGINAL', Storage::disk('public')->get($invoice->invoice_file_path));
        $this->assertSame('public', $this->karigarDisk($invoice), 'the row is not flipped onto a file it does not own');
        $this->assertStringContainsString('destination_conflict', $this->manifest());
    }

    public function test_an_identical_destination_is_resumed_without_rewriting(): void
    {
        $invoice = $this->karigarInvoiceOnPublicDisk('SAME_BYTES');
        Storage::disk('local')->put($invoice->invoice_file_path, 'SAME_BYTES');

        $this->artisan('karigar-invoices:relocate-attachments', ['--execute' => true])->assertExitCode(0);

        $this->assertSame('local', $this->karigarDisk($invoice));
        $this->assertSame('SAME_BYTES', Storage::disk('local')->get($invoice->invoice_file_path));
        $this->assertStringContainsString('identical_copy_resumed', $this->manifest());
    }

    public function test_a_failed_copy_leaves_no_destination_and_deletes_nothing_it_did_not_create(): void
    {
        $invoice = $this->karigarInvoiceOnPublicDisk('WILL_NOT_LAND');
        $dir = dirname($invoice->invoice_file_path);
        Storage::disk('local')->put("{$dir}/neighbour.pdf", 'NEIGHBOUR');
        // A stray temporary left by a mover that died mid-copy: not ours to delete.
        Storage::disk('local')->put($invoice->invoice_file_path.'.relocating-deadbeefdeadbeef', 'PARTIAL');
        chmod(Storage::disk('local')->path($dir), 0555);

        try {
            $this->artisan('karigar-invoices:relocate-attachments', ['--execute' => true])->assertExitCode(1);
        } finally {
            chmod(Storage::disk('local')->path($dir), 0755);
        }

        $this->assertFalse(Storage::disk('local')->exists($invoice->invoice_file_path));
        $this->assertSame('public', $this->karigarDisk($invoice));
        $this->assertSame('NEIGHBOUR', Storage::disk('local')->get("{$dir}/neighbour.pdf"));
        $this->assertSame('PARTIAL', Storage::disk('local')->get($invoice->invoice_file_path.'.relocating-deadbeefdeadbeef'),
            "another mover's temporary is left alone");
        $this->assertCount(2, Storage::disk('local')->files($dir), 'and this mover left none of its own');
    }

    public function test_a_stray_temporary_from_an_interrupted_run_does_not_become_the_file(): void
    {
        $invoice = $this->karigarInvoiceOnPublicDisk('FULL_AND_VERIFIED');
        Storage::disk('local')->put($invoice->invoice_file_path.'.relocating-0123456789abcdef', 'TRUNCA');

        $this->artisan('karigar-invoices:relocate-attachments', ['--execute' => true])->assertExitCode(0);

        $this->assertSame('FULL_AND_VERIFIED', Storage::disk('local')->get($invoice->invoice_file_path));
        $this->assertSame('local', $this->karigarDisk($invoice));
    }

    // ────────────────────────────────────────────────────────────────────
    // Signature mover
    // ────────────────────────────────────────────────────────────────────

    public function test_a_conflicting_signature_destination_is_refused_without_touching_the_ledger(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $path = "signatures/{$shop->id}/sig.png";
        $this->publicSignature((int) $shop->id, $path, 'PUBLIC_SIG');
        Storage::disk('local')->put($path, 'DIFFERENT_PRIVATE_SIG');

        $this->artisan('signatures:relocate', ['--execute' => true])->assertExitCode(1);

        $this->assertSame('DIFFERENT_PRIVATE_SIG', Storage::disk('local')->get($path));
        $this->assertSame('public', $this->signatureRow((int) $shop->id)->digital_signature_disk);
        $this->assertSame(0, DB::table('signature_relocations')->count());
    }

    public function test_an_identical_signature_destination_is_resumed_and_recorded(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $path = "signatures/{$shop->id}/sig.png";
        $this->publicSignature((int) $shop->id, $path, 'SAME_SIG');
        Storage::disk('local')->put($path, 'SAME_SIG');

        $this->artisan('signatures:relocate', ['--execute' => true])->assertExitCode(0);

        $this->assertSame('local', $this->signatureRow((int) $shop->id)->digital_signature_disk);
        $this->assertSame(1, DB::table('signature_relocations')->count());
        $this->assertStringContainsString('identical_copy_resumed', $this->manifest());
    }

    /**
     * A settings row naming ANOTHER shop's signature path is refused before any
     * byte is written. The old order copied first and let the ledger refuse
     * afterwards — by then the other shop's private file had been overwritten.
     */
    public function test_a_foreign_shop_signature_path_is_refused_before_any_write(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $foreignPath = "signatures/{$shopB->id}/sig.png";
        Storage::disk('local')->put($foreignPath, 'SHOP_B_PRIVATE');
        $this->publicSignature((int) $shopA->id, $foreignPath, 'WHATEVER_IS_PUBLIC');

        $this->artisan('signatures:relocate', ['--execute' => true])->assertExitCode(1);

        $this->assertSame('SHOP_B_PRIVATE', Storage::disk('local')->get($foreignPath), "shop B's file is untouched");
        $this->assertSame('public', $this->signatureRow((int) $shopA->id)->digital_signature_disk);
        $this->assertSame(0, DB::table('signature_relocations')->count());
        $this->assertStringContainsString('foreign_path', $this->manifest());
    }

    // ────────────────────────────────────────────────────────────────────
    // Existing relocation evidence (second review)
    //
    // An inconsistent-state recovery case, not a normal upload path: a ledger
    // row already vouches for (shop, path) with one digest, and the settings
    // row names that path on the public disk again. The ledger is what lets an
    // immutable snapshot find its original bytes, so its identity is not the
    // mover's to rewrite.
    // ────────────────────────────────────────────────────────────────────

    /** A ledger row for $path recording $bytes' digest, dated in the past. */
    private function existingEvidence(int $shopId, string $path, string $bytes): object
    {
        DB::table('signature_relocations')->insert([
            'shop_id' => $shopId, 'path' => $path, 'source_disk' => 'public', 'target_disk' => 'local',
            'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'relocated_at' => '2026-01-01 00:00:00',
        ]);

        return DB::table('signature_relocations')->where('shop_id', $shopId)->where('path', $path)->first();
    }

    private function assertEvidenceUnchanged(object $before): void
    {
        $this->assertEquals($before, DB::table('signature_relocations')->where('id', $before->id)->first(),
            'the existing ledger row keeps its digest, target, size and date');
        $this->assertSame(1, DB::table('signature_relocations')->count());
    }

    public function test_evidence_for_other_bytes_is_refused_even_when_source_and_destination_agree(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $path = "signatures/{$shop->id}/sig.png";
        $evidence = $this->existingEvidence((int) $shop->id, $path, 'SIGNATURE_A');
        $this->publicSignature((int) $shop->id, $path, 'SIGNATURE_B');
        Storage::disk('local')->put($path, 'SIGNATURE_B');

        $this->artisan('signatures:relocate', ['--execute' => true])->assertExitCode(1);

        $this->assertEvidenceUnchanged($evidence);
        $this->assertSame('public', $this->signatureRow((int) $shop->id)->digital_signature_disk);
        $this->assertSame('SIGNATURE_B', Storage::disk('public')->get($path));
        $this->assertSame('SIGNATURE_B', Storage::disk('local')->get($path));
        $this->assertStringContainsString('ledger_conflict', $this->manifest());
    }

    public function test_evidence_for_other_bytes_is_refused_before_anything_is_published(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $path = "signatures/{$shop->id}/sig.png";
        $evidence = $this->existingEvidence((int) $shop->id, $path, 'SIGNATURE_A');
        $this->publicSignature((int) $shop->id, $path, 'SIGNATURE_B');

        $this->artisan('signatures:relocate', ['--execute' => true])->assertExitCode(1);

        $this->assertEvidenceUnchanged($evidence);
        $this->assertFalse(Storage::disk('local')->exists($path), 'nothing was published under conflicting evidence');
        $this->assertSame('public', $this->signatureRow((int) $shop->id)->digital_signature_disk);
        $this->assertSame('SIGNATURE_B', Storage::disk('public')->get($path));
        $this->assertStringContainsString('ledger_conflict', $this->manifest());
    }

    public function test_identical_evidence_is_reused_not_rewritten(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $path = "signatures/{$shop->id}/sig.png";
        $evidence = $this->existingEvidence((int) $shop->id, $path, 'SIGNATURE_A');
        $this->publicSignature((int) $shop->id, $path, 'SIGNATURE_A');

        $this->artisan('signatures:relocate', ['--execute' => true])->assertExitCode(0);

        $this->assertEvidenceUnchanged($evidence);
        $this->assertSame('local', $this->signatureRow((int) $shop->id)->digital_signature_disk);
        $this->assertSame('SIGNATURE_A', Storage::disk('local')->get($path));
    }
}

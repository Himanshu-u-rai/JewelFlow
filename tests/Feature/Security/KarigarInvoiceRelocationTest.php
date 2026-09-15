<?php

namespace Tests\Feature\Security;

use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-02 part two — relocating attachments that ALREADY sit on the public disk.
 *
 * Commit 1e61896 stopped the exposed tree GROWING (new uploads go to the private
 * disk) and made the download route read whichever disk each row records. It did
 * not move a single existing byte. Those files are still served statically by
 * nginx off the public/storage symlink, with no auth, no tenant scope and no
 * shop_id check.
 *
 * The property that makes a row-at-a-time relocation safe is a consequence of
 * 1e61896: because the route dispatches on $invoice->invoice_file_disk rather
 * than a hardcoded disk, the application is CORRECT AT EVERY INTERMEDIATE STATE.
 * A half-finished run leaves some rows public and some private and every one of
 * them still downloads. That is what makes the procedure resumable rather than a
 * big-bang cutover needing a maintenance window.
 *
 * Safety contract asserted below:
 *   - dry run is the DEFAULT; writing requires an explicit --execute
 *   - bytes are verified at the destination (sha256) BEFORE metadata is flipped
 *   - the original is preserved until a separate, explicit purge
 *   - a failed row keeps invoice_file_disk = 'public', so it still downloads
 *   - re-running is a no-op, so an interrupted run is simply re-run
 */
class KarigarInvoiceRelocationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    private const COMMAND = 'karigar-invoices:relocate-attachments';

    private function createKarigar(int $shopId): Karigar
    {
        return TenantContext::runFor($shopId, fn () => Karigar::create([
            'shop_id' => $shopId,
            'name' => 'Relocation Karigar',
            'mobile' => '98'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        ]));
    }

    /** A pre-fix row: metadata says 'public', and the bytes really are there. */
    private function publicDiskInvoice(int $shopId, int $karigarId, int $userId, string $basename, string $bytes): KarigarInvoice
    {
        $path = "karigar-invoices/{$shopId}/{$basename}";
        Storage::disk('public')->put($path, $bytes);

        return TenantContext::runFor($shopId, fn () => KarigarInvoice::create([
            'shop_id' => $shopId,
            'karigar_id' => $karigarId,
            'mode' => KarigarInvoice::MODE_PURCHASE,
            'karigar_invoice_number' => 'KI-'.$shopId.'-'.uniqid(),
            'karigar_invoice_date' => now()->toDateString(),
            'payment_status' => KarigarInvoice::PAYMENT_UNPAID,
            'amount_paid' => 0,
            'invoice_file_path' => $path,
            'invoice_file_disk' => 'public',
            'created_by_user_id' => $userId,
        ]));
    }

    private function recordedDisk(KarigarInvoice $invoice): ?string
    {
        return DB::table('karigar_invoices')->where('id', $invoice->id)->value('invoice_file_disk');
    }

    private function requestAs(User $user, string $url)
    {
        $this->actingAs($user);

        return TenantContext::runFor($user->shop_id, fn () => $this->get($url));
    }

    /**
     * R-01 — the default must be inert. An operator who types the command name and
     * nothing else must not move production bytes.
     *
     * Production change that would break this: making the write path conditional on
     * anything other than the explicit --execute flag.
     */
    public function test_dry_run_is_the_default_and_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'dry.pdf', 'DRY_RUN_BYTES_01');

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertSame('public', $this->recordedDisk($invoice),
            'a dry run must not flip the recorded disk');
        $this->assertFalse(Storage::disk('local')->exists($invoice->invoice_file_path),
            'a dry run must not copy bytes to the private disk');
        $this->assertTrue(Storage::disk('public')->exists($invoice->invoice_file_path),
            'a dry run must not remove the original');
    }

    /** R-02 — the dry run must leave a reviewable manifest of what it WOULD do. */
    public function test_dry_run_writes_a_manifest_listing_the_candidate_rows(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'manifest.pdf', 'MANIFEST_BYTES_01');

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $manifests = Storage::disk('local')->files('relocation-manifests');
        $this->assertNotEmpty($manifests, 'a dry run must leave a manifest to review');

        $body = Storage::disk('local')->get($manifests[0]);
        $this->assertStringContainsString((string) $invoice->id, $body);
        $this->assertStringContainsString($invoice->invoice_file_path, $body);
        $this->assertStringContainsString(hash('sha256', 'MANIFEST_BYTES_01'), $body,
            'the manifest must record the source digest so copies can be verified');
    }

    /** R-03 — with --execute the bytes land on the private disk, byte-identical. */
    public function test_execute_copies_bytes_to_the_private_disk_and_flips_the_recorded_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'move.pdf', 'RELOCATED_BYTES_01');

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists($invoice->invoice_file_path));
        $this->assertSame('RELOCATED_BYTES_01', Storage::disk('local')->get($invoice->invoice_file_path),
            'the copy must be byte-identical');
        $this->assertSame('local', $this->recordedDisk($invoice),
            'the row must now record the private disk');
    }

    /**
     * R-04 — originals survive the relocation. Deleting the source in the same pass
     * would make the run unrecoverable the moment a copy silently truncated.
     */
    public function test_the_public_original_is_preserved_by_the_relocation_pass(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'keep.pdf', 'PRESERVED_BYTES_01');

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $this->assertTrue(Storage::disk('public')->exists($invoice->invoice_file_path),
            'the original must remain until an explicit purge');
    }

    /**
     * R-05 — the end-to-end property that actually matters to a user. The same
     * authorised request returns the same bytes before and after relocation.
     */
    public function test_relocation_is_invisible_to_the_authenticated_download_route(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'e2e.pdf', 'END_TO_END_BYTES_01');

        $before = $this->requestAs($owner, route('karigar-invoices.file', $invoice));
        $before->assertOk();
        $this->assertSame('END_TO_END_BYTES_01', $before->streamedContent());

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $after = $this->requestAs($owner, route('karigar-invoices.file', $invoice->fresh()));
        $after->assertOk();
        $this->assertSame('END_TO_END_BYTES_01', $after->streamedContent(),
            'relocation must not strand an attachment');
    }

    /**
     * R-06 — a missing source is a loud failure, and the row is LEFT ALONE.
     *
     * Flipping the disk for a row we could not copy would break a download that
     * currently works. Fail-closed here means "change nothing".
     */
    public function test_a_row_whose_source_is_missing_is_reported_and_left_untouched(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'ghost.pdf', 'GHOST_BYTES_01');

        // Simulate the row whose file was removed out from under it.
        Storage::disk('public')->delete($invoice->invoice_file_path);

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(1);

        $this->assertSame('public', $this->recordedDisk($invoice),
            'a row that could not be copied must keep its original disk');
        $this->assertFalse(Storage::disk('local')->exists($invoice->invoice_file_path));
    }

    /**
     * R-07 — one bad row must not abandon the good ones. The run is per-row, not a
     * single transaction, precisely so an interrupted or partly-failing run still
     * makes forward progress.
     */
    public function test_one_failing_row_does_not_block_the_healthy_rows(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $broken = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'broken.pdf', 'BROKEN_BYTES');
        $healthy = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'healthy.pdf', 'HEALTHY_BYTES');
        Storage::disk('public')->delete($broken->invoice_file_path);

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(1);

        $this->assertSame('public', $this->recordedDisk($broken));
        $this->assertSame('local', $this->recordedDisk($healthy),
            'a healthy row must relocate even when a sibling row fails');
    }

    /**
     * R-08 — resumability. Re-running after a completed (or interrupted) run must
     * be a no-op, so the recovery procedure is simply "run it again".
     */
    public function test_a_second_run_is_a_no_op(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'twice.pdf', 'IDEMPOTENT_BYTES_01');

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('0 relocated')
            ->assertExitCode(0);

        $this->assertSame('local', $this->recordedDisk($invoice));
        $this->assertSame('IDEMPOTENT_BYTES_01', Storage::disk('local')->get($invoice->invoice_file_path));
    }

    /**
     * R-09 — blast-radius control. An operator must be able to relocate one shop,
     * confirm with that shop, and only then widen.
     */
    public function test_the_shop_filter_limits_the_blast_radius(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $invoiceA = $this->publicDiskInvoice($shopA->id, $this->createKarigar($shopA->id)->id, $ownerA->id, 'a.pdf', 'SHOP_A_BYTES');
        $invoiceB = $this->publicDiskInvoice($shopB->id, $this->createKarigar($shopB->id)->id, $ownerB->id, 'b.pdf', 'SHOP_B_BYTES');

        $this->artisan(self::COMMAND, ['--execute' => true, '--shop' => $shopA->id])->assertExitCode(0);

        $this->assertSame('local', $this->recordedDisk($invoiceA));
        $this->assertSame('public', $this->recordedDisk($invoiceB),
            'a --shop run must not touch another tenant\'s rows');
    }

    /** R-10 — --limit caps how much a single run can move. */
    public function test_the_limit_option_caps_the_rows_processed_in_one_run(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $first = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'one.pdf', 'ONE_BYTES');
        $second = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'two.pdf', 'TWO_BYTES');

        $this->artisan(self::COMMAND, ['--execute' => true, '--limit' => 1])->assertExitCode(0);

        $disks = [$this->recordedDisk($first), $this->recordedDisk($second)];
        sort($disks);
        $this->assertSame(['local', 'public'], $disks,
            '--limit 1 must relocate exactly one of the two rows');
    }

    /**
     * R-11 — verification is a real check, not a rubber stamp. Corrupt the private
     * copy and --verify must notice.
     */
    public function test_verify_detects_a_corrupted_destination_copy(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'corrupt.pdf', 'GOOD_BYTES_01');

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $this->artisan(self::COMMAND, ['--verify' => true])->assertExitCode(0);

        Storage::disk('local')->put($invoice->invoice_file_path, 'TAMPERED');

        $this->artisan(self::COMMAND, ['--verify' => true])->assertExitCode(1);
    }

    /**
     * R-12 — the purge must refuse while any original is unverified. This is the
     * gate that keeps "originals preserved until verification" true by construction
     * rather than by operator discipline.
     */
    public function test_purge_refuses_when_a_copy_does_not_verify(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'purge-bad.pdf', 'PURGE_BYTES_01');

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        Storage::disk('local')->put($invoice->invoice_file_path, 'TAMPERED');

        $this->artisan(self::COMMAND, ['--execute' => true, '--purge-originals' => true])->assertExitCode(1);

        $this->assertTrue(Storage::disk('public')->exists($invoice->invoice_file_path),
            'an unverified original must never be deleted');
    }

    /**
     * R-13 — positive control for R-12: with a good copy the purge does proceed, so
     * R-12 failed because verification failed, not because purge never works.
     */
    public function test_purge_removes_the_original_once_the_copy_verifies(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'purge-ok.pdf', 'PURGE_BYTES_02');

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $this->artisan(self::COMMAND, ['--execute' => true, '--purge-originals' => true])->assertExitCode(0);

        $this->assertFalse(Storage::disk('public')->exists($invoice->invoice_file_path),
            'a verified original must be removed from the web-served tree');
        $this->assertTrue(Storage::disk('local')->exists($invoice->invoice_file_path));

        // And the file is still served to an authorised user afterwards.
        $response = $this->requestAs($owner, route('karigar-invoices.file', $invoice->fresh()));
        $response->assertOk();
        $this->assertSame('PURGE_BYTES_02', $response->streamedContent());
    }

    /** R-14 — the purge is itself a write, so it must also honour the dry-run default. */
    public function test_purge_without_execute_deletes_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'purge-dry.pdf', 'PURGE_BYTES_03');

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $this->artisan(self::COMMAND, ['--purge-originals' => true])->assertExitCode(0);

        $this->assertTrue(Storage::disk('public')->exists($invoice->invoice_file_path),
            'a purge dry run must not delete originals');
    }

    /**
     * R-15 — a finalized invoice must relocate too. Most attachments worth moving
     * belong to invoices that have been paid, and those rows are under the
     * constitutionally-protected finalized guard trigger (Art. IX.A #15).
     */
    public function test_a_finalized_invoice_relocates_without_touching_the_guard(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->createKarigar($shop->id);
        $invoice = $this->publicDiskInvoice($shop->id, $karigar->id, $owner->id, 'final.pdf', 'FINALIZED_BYTES_01');

        DB::table('karigar_invoices')->where('id', $invoice->id)
            ->update(['payment_status' => KarigarInvoice::PAYMENT_PAID]);

        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);

        $this->assertSame('local', $this->recordedDisk($invoice));
        $this->assertSame('FINALIZED_BYTES_01', Storage::disk('local')->get($invoice->invoice_file_path));
    }
}

<?php

namespace App\Console\Commands;

use App\Models\KarigarInvoice;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * S3-02 remediation, part two — move karigar invoice attachments off the
 * web-served public disk.
 *
 * WHY THIS IS A SEPARATE, OPT-IN PROCEDURE
 * ----------------------------------------
 * Commit 1e61896 stopped the exposure growing (new uploads go to the private
 * disk) and made KarigarInvoiceController::showFile() dispatch on the disk each
 * row RECORDS rather than a hardcoded one. It deliberately moved no bytes:
 * blindly repointing every row at the private disk would have stranded every
 * attachment that is physically still on the public disk.
 *
 * That dispatch-on-recorded-disk property is what makes this command safe. The
 * application is correct at EVERY intermediate state — a half-finished run
 * leaves some rows public and some private, and all of them still download. So
 * relocation needs no maintenance window, and recovery from an interruption is
 * simply "run it again".
 *
 * SAFETY CONTRACT (each clause has a test in KarigarInvoiceRelocationTest)
 *   - Dry run is the DEFAULT. Nothing is written without --execute.        R-01
 *   - Every run leaves a JSONL manifest on the private disk.               R-02
 *   - Bytes are copied and re-hashed at the DESTINATION before any
 *     metadata is flipped.                                                 R-03
 *   - The public original is preserved; --purge-originals is a separate,
 *     later, explicitly-gated pass.                                        R-04
 *   - A row that cannot be copied keeps invoice_file_disk = 'public', so a
 *     download that works today keeps working. Fail-closed here means
 *     "change nothing".                                                    R-06
 *   - One failing row does not abandon the healthy ones.                   R-07
 *   - Re-running is a no-op.                                              R-08
 *   - --shop and --limit bound the blast radius.                      R-09/R-10
 *   - --purge-originals refuses WHOLESALE if any copy in scope fails
 *     verification.                                                   R-12/R-13
 *
 * CONSTITUTIONAL NOTE
 * karigar_invoices_finalized_guard_trigger (Art. IX.A #15) is BEFORE UPDATE OR
 * DELETE and freezes totals, invoice number, invoice date and karigar_id once
 * payment_status leaves 'unpaid'. invoice_file_path and invoice_file_disk are
 * NOT in the frozen set, so this command never needs the trigger touched —
 * which would be forbidden anyway. Pinned by
 * KarigarInvoiceAttachmentTest::test_finalized_invoice_still_accepts_attachment_metadata_relocation
 * and exercised end-to-end by R-15.
 *
 * TENANCY NOTE
 * This runs in the console, where BelongsToShop::resolveTenantShopId() returns
 * null and the global shop scope is therefore INERT. Every query here is raw
 * DB::table with explicit predicates so the scoping is visible rather than
 * assumed, and --shop is an explicit WHERE rather than an ambient context.
 */
class RelocateKarigarInvoiceAttachments extends Command
{
    protected $signature = 'karigar-invoices:relocate-attachments
        {--execute : Perform writes. Without this flag the command is a dry run and changes nothing.}
        {--shop= : Restrict to a single shop_id.}
        {--limit= : Process at most N rows in this run.}
        {--verify : Verify already-relocated rows instead of relocating any more.}
        {--purge-originals : Delete public-disk originals whose private copy verifies. Requires --execute to actually delete.}';

    protected $description = 'Move karigar invoice attachments from the web-served public disk to the private disk (dry run unless --execute).';

    private const SOURCE_DISK = 'public';

    /**
     * What this command relocates. Overridden by RelocatePurchaseInvoiceImages
     * (S3-03), which is the same procedure on stock_purchases.
     *
     * ponytail: a subclass overriding four constants. Extract an abstract base
     * if a third attachment table ever needs this.
     */
    protected const TABLE = 'karigar_invoices';

    protected const PATH_COLUMN = 'invoice_file_path';

    protected const DISK_COLUMN = 'invoice_file_disk';

    protected const MANIFEST_PREFIX = 'karigar-invoices';

    private const MANIFEST_DIR = 'relocation-manifests';

    /** @var array<int, array<string, mixed>> */
    private array $manifest = [];

    protected function targetDisk(): string
    {
        return KarigarInvoice::ATTACHMENT_DISK;
    }

    public function handle(): int
    {
        if (self::SOURCE_DISK === $this->targetDisk()) {
            $this->error('Source and target disk are the same; refusing to run.');

            return self::FAILURE;
        }

        $mode = match (true) {
            (bool) $this->option('verify') => 'verify',
            (bool) $this->option('purge-originals') => 'purge',
            default => 'relocate',
        };

        $this->line(sprintf(
            'Mode: %s | %s%s',
            $mode,
            $this->option('execute') ? 'EXECUTE (writes enabled)' : 'DRY RUN (no writes)',
            $this->option('shop') ? ' | shop='.$this->option('shop') : ''
        ));

        try {
            return match ($mode) {
                'verify' => $this->verifyPass(),
                'purge' => $this->purgePass(),
                default => $this->relocatePass(),
            };
        } finally {
            $this->writeManifest($mode);
        }
    }

    // ---------------------------------------------------------------- queries

    /** Rows still recorded on the public disk — the relocation backlog. */
    private function backlog(): \Illuminate\Support\Collection
    {
        return $this->baseQuery()
            ->where(static::DISK_COLUMN, self::SOURCE_DISK)
            ->when($this->option('limit'), fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();
    }

    /** Rows already recorded on the private disk — the verification population. */
    private function relocated(): \Illuminate\Support\Collection
    {
        return $this->baseQuery()
            ->where(static::DISK_COLUMN, $this->targetDisk())
            ->get();
    }

    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table(static::TABLE)
            ->select('id', 'shop_id', static::PATH_COLUMN.' as path', static::DISK_COLUMN.' as disk')
            ->whereNotNull(static::PATH_COLUMN)
            ->when($this->option('shop'), fn ($q) => $q->where('shop_id', (int) $this->option('shop')))
            ->orderBy('id');
    }

    // ----------------------------------------------------------------- passes

    private function relocatePass(): int
    {
        $source = Storage::disk(self::SOURCE_DISK);
        $target = Storage::disk($this->targetDisk());

        $rows = $this->backlog();
        $relocated = $failed = $planned = 0;

        foreach ($rows as $row) {
            $path = $row->path;

            if (! $source->exists($path)) {
                // The row points at a file that is not there. Flipping the disk
                // would turn a broken-but-known state into a broken-and-wrong
                // one, so leave the row exactly as it is and report it.
                $this->record($row, 'failed', 'source_missing');
                $this->warn("  #{$row->id} source missing: {$path}");
                $failed++;
                continue;
            }

            $sourceDigest = $this->digest($source, $path);
            if ($sourceDigest === null) {
                $this->record($row, 'failed', 'source_unreadable');
                $this->warn("  #{$row->id} source unreadable: {$path}");
                $failed++;
                continue;
            }

            if (! $this->option('execute')) {
                $this->record($row, 'planned', 'would_relocate', $sourceDigest);
                $planned++;
                continue;
            }

            if (! $this->copyVerified($source, $target, $path, $sourceDigest)) {
                // Remove the bad copy. A row still recorded on 'public' cannot
                // have a legitimate file at this path on the private disk — a
                // real new upload would already have flipped the row — so the
                // only thing here is our own failed attempt.
                $target->delete($path);
                $this->record($row, 'failed', 'copy_verification_failed', $sourceDigest);
                $this->warn("  #{$row->id} copy did not verify: {$path}");
                $failed++;
                continue;
            }

            // Targeted, guarded update: both the id AND the values we read are
            // in the predicate, so a row that changed underneath us (a
            // concurrent re-upload) is not clobbered. Fires the finalized guard
            // trigger but touches none of its frozen columns.
            $updated = DB::table(static::TABLE)
                ->where('id', $row->id)
                ->where(static::DISK_COLUMN, self::SOURCE_DISK)
                ->where(static::PATH_COLUMN, $path)
                ->update([static::DISK_COLUMN => $this->targetDisk()]);

            if ($updated !== 1) {
                $this->record($row, 'failed', 'row_changed_concurrently', $sourceDigest);
                $this->warn("  #{$row->id} row changed during relocation; left alone.");
                $failed++;
                continue;
            }

            $this->record($row, 'relocated', 'ok', $sourceDigest);
            $relocated++;
        }

        $this->line(sprintf(
            'Result: %d relocated, %d failed, %d planned, %d candidates examined.',
            $relocated, $failed, $planned, $rows->count()
        ));

        if (! $this->option('execute')) {
            $this->line('Dry run — nothing was written. Re-run with --execute to relocate.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function verifyPass(): int
    {
        $result = $this->verifyRelocated();

        $this->line(sprintf(
            'Verified: %d full digest matches, %d existence-only (original already purged), %d FAILED.',
            $result['matched'], $result['existence_only'], count($result['failures'])
        ));

        foreach ($result['failures'] as $failure) {
            $this->warn("  #{$failure['id']} {$failure['reason']}: {$failure['path']}");
        }

        return $result['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function purgePass(): int
    {
        // The purge is gated on a fresh verification of EVERY row in scope, not
        // row-by-row. "Originals preserved until verification" has to be true by
        // construction; a per-row gate would let a half-trustworthy run delete
        // the only good copies of the rows that happened to pass.
        $result = $this->verifyRelocated();

        if ($result['failures'] !== []) {
            $this->error(sprintf(
                'Refusing to purge: %d attachment(s) failed verification. No original was deleted.',
                count($result['failures'])
            ));
            foreach ($result['failures'] as $failure) {
                $this->warn("  #{$failure['id']} {$failure['reason']}: {$failure['path']}");
            }

            return self::FAILURE;
        }

        $source = Storage::disk(self::SOURCE_DISK);
        $purged = 0;

        foreach ($result['purgeable'] as $row) {
            if (! $this->option('execute')) {
                $this->record($row, 'planned', 'would_purge_original');
                $purged++;
                continue;
            }

            if ($source->delete($row->path)) {
                $this->record($row, 'purged', 'original_deleted');
                $purged++;
            } else {
                $this->record($row, 'failed', 'original_delete_failed');
                $this->warn("  #{$row->id} could not delete original: {$row->path}");
            }
        }

        $this->line(sprintf(
            '%s %d original(s) from the web-served tree.',
            $this->option('execute') ? 'Purged' : 'Would purge',
            $purged
        ));

        if (! $this->option('execute')) {
            $this->line('Dry run — nothing was deleted. Re-run with --execute --purge-originals.');
        }

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Compare each relocated row's private copy against its surviving public
     * original. Where the original is already gone the check degrades to
     * existence-only, and that is reported honestly rather than counted as a
     * match — a purged row cannot be re-verified against anything.
     *
     * @return array{matched:int, existence_only:int, failures:array<int, array<string,string>>, purgeable:array<int, object>}
     */
    private function verifyRelocated(): array
    {
        $source = Storage::disk(self::SOURCE_DISK);
        $target = Storage::disk($this->targetDisk());

        $matched = 0;
        $existenceOnly = 0;
        $failures = [];
        $purgeable = [];

        foreach ($this->relocated() as $row) {
            $path = $row->path;

            if (! $target->exists($path)) {
                $failures[] = ['id' => (string) $row->id, 'path' => $path, 'reason' => 'private copy missing'];
                continue;
            }

            if (! $source->exists($path)) {
                $existenceOnly++;
                continue;
            }

            $sourceDigest = $this->digest($source, $path);
            $targetDigest = $this->digest($target, $path);

            if ($sourceDigest === null || $targetDigest === null) {
                $failures[] = ['id' => (string) $row->id, 'path' => $path, 'reason' => 'unreadable during verification'];
                continue;
            }

            if (! hash_equals($sourceDigest, $targetDigest)) {
                $failures[] = ['id' => (string) $row->id, 'path' => $path, 'reason' => 'digest mismatch'];
                continue;
            }

            $matched++;
            $purgeable[] = $row;
        }

        return [
            'matched' => $matched,
            'existence_only' => $existenceOnly,
            'failures' => $failures,
            'purgeable' => $purgeable,
        ];
    }

    /**
     * Copy, then re-read the DESTINATION and hash what is actually there.
     * Trusting put()'s return value would accept a truncated write on a full
     * disk — and the configured disks have 'throw' => false, so a failed write
     * is a false return, not an exception.
     */
    private function copyVerified(Filesystem $source, Filesystem $target, string $path, string $expectedDigest): bool
    {
        $stream = $source->readStream($path);
        if ($stream === null || $stream === false) {
            return false;
        }

        $written = $target->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($written !== true) {
            return false;
        }

        $actual = $this->digest($target, $path);

        return $actual !== null && hash_equals($expectedDigest, $actual);
    }

    /** Streamed sha256 so a large attachment is never held in memory. */
    private function digest(Filesystem $disk, string $path): ?string
    {
        $stream = $disk->readStream($path);
        if ($stream === null || $stream === false) {
            return null;
        }

        $context = hash_init('sha256');
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 256);
            if ($chunk === false) {
                fclose($stream);

                return null;
            }
            hash_update($context, $chunk);
        }
        fclose($stream);

        return hash_final($context);
    }

    private function record(object $row, string $action, string $reason, ?string $digest = null): void
    {
        $this->manifest[] = [
            'table' => static::TABLE,
            'invoice_id' => $row->id,
            'shop_id' => $row->shop_id,
            'path' => $row->path,
            'from_disk' => $row->disk,
            'to_disk' => $this->targetDisk(),
            'source_sha256' => $digest,
            'action' => $action,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ];
    }

    /**
     * Always written, including on the failure path — an operator investigating
     * an aborted run needs the manifest most.
     */
    private function writeManifest(string $mode): void
    {
        if ($this->manifest === []) {
            return;
        }

        $lines = array_map(
            static fn (array $entry) => json_encode($entry, JSON_UNESCAPED_SLASHES),
            $this->manifest
        );

        $file = sprintf(
            '%s/%s-%s-%s-%s.jsonl',
            self::MANIFEST_DIR,
            static::MANIFEST_PREFIX,
            $mode,
            $this->option('execute') ? 'execute' : 'dryrun',
            now()->format('Ymd-His-v')
        );

        Storage::disk($this->targetDisk())->put($file, implode("\n", $lines)."\n");

        $this->line("Manifest: {$file} (on the {$this->targetDisk()} disk)");
    }
}

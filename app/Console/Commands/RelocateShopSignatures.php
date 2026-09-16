<?php

namespace App\Console\Commands;

use App\Services\InvoiceSignatureRenderer;
use App\Services\SignatureStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * S3-04 remediation, part two — move shop digital signatures off the web-served
 * public disk.
 *
 * Deliberately the same shape as RelocateKarigarInvoiceAttachments (S3-02): dry
 * run by default, copy-and-rehash at the destination before any metadata moves,
 * originals preserved until a separate explicitly-gated purge, JSONL manifest,
 * --shop/--limit to bound the blast radius. Read that command's docblock for the
 * reasoning; it is not repeated here.
 *
 * WHAT IS DIFFERENT ABOUT SIGNATURES, AND WHY IT MATTERS
 * -----------------------------------------------------
 * A karigar attachment is referenced by exactly one row, so flipping that row's
 * disk is a complete description of the move. A signature is referenced by:
 *
 *   1. shop_billing_settings.digital_signature_path/_disk — the CURRENT
 *      selection, mutable, one row per shop; and
 *   2. every invoice_render_snapshots row and quick_bills.shop_snapshot that
 *      was captured while that signature was current — IMMUTABLE, unbounded in
 *      number, and explicitly not rewritable.
 *
 * This command therefore updates (1) and NEVER touches (2). That is only safe
 * because InvoiceSignatureRenderer resolves a recorded path across
 * ALLOWED_DISKS and treats the recorded disk as a location hint (pinned by
 * G-19). Without that property, purging a public original would break the
 * reprint of every invoice finalized before the move.
 *
 * SUPERSEDES AN EARLIER CLAIM
 * A previous report of mine said "12 files is small enough to relocate by hand."
 * That is withdrawn. The count was never the risk. Copying bytes by hand gives
 * no digest verification, no record of which rows were flipped, no resumption
 * point if the session dies midway, and no way to tell afterwards whether a
 * signature that stopped rendering was missed, truncated or never copied. The
 * files a manual pass is most likely to mishandle are precisely the ones
 * referenced by finalized invoices, where the bytes are unreconstructable.
 *
 * SIGNATURES ARE APPEND-ONLY
 * SignatureStore never deletes, so a shop's public tree holds its current
 * signature AND every superseded version. Superseded versions are not named by
 * shop_billing_settings at all — only by snapshots. Those files are enumerated
 * by --orphans and are NOT relocated by the default pass, because moving a file
 * no row names would strip the only pointer that exists to it.
 */
class RelocateShopSignatures extends Command
{
    protected $signature = 'signatures:relocate
        {--execute : Perform writes. Without this flag the command is a dry run and changes nothing.}
        {--shop= : Restrict to a single shop_id.}
        {--limit= : Process at most N rows in this run.}
        {--verify : Verify already-relocated rows instead of relocating any more.}
        {--orphans : Report public-tree signature files that no settings row names. Reports only; never moves or deletes.}
        {--purge-originals : Delete public-disk originals whose private copy verifies. Requires --execute to actually delete.}';

    protected $description = 'Move shop digital signatures from the web-served public disk to the private disk (dry run unless --execute).';

    private const SOURCE_DISK = 'public';

    private const MANIFEST_DIR = 'relocation-manifests';

    /** @var array<int, array<string, mixed>> */
    private array $manifest = [];

    private function targetDisk(): string
    {
        return SignatureStore::DISK;
    }

    public function handle(): int
    {
        if (self::SOURCE_DISK === $this->targetDisk()) {
            $this->error('Source and target disk are the same; refusing to run.');

            return self::FAILURE;
        }

        $mode = match (true) {
            (bool) $this->option('orphans') => 'orphans',
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
                'orphans' => $this->orphanPass(),
                'verify' => $this->verifyPass(),
                'purge' => $this->purgePass(),
                default => $this->relocatePass(),
            };
        } finally {
            $this->writeManifest($mode);
        }
    }

    // ---------------------------------------------------------------- queries

    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('shop_billing_settings')
            ->select('id', 'shop_id', 'digital_signature_path', 'digital_signature_disk')
            ->whereNotNull('digital_signature_path')
            ->when($this->option('shop'), fn ($q) => $q->where('shop_id', (int) $this->option('shop')))
            ->orderBy('shop_id');
    }

    private function backlog(): \Illuminate\Support\Collection
    {
        return $this->baseQuery()
            ->where('digital_signature_disk', self::SOURCE_DISK)
            ->when($this->option('limit'), fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();
    }

    private function relocated(): \Illuminate\Support\Collection
    {
        return $this->baseQuery()->where('digital_signature_disk', $this->targetDisk())->get();
    }

    // ----------------------------------------------------------------- passes

    private function relocatePass(): int
    {
        $source = Storage::disk(self::SOURCE_DISK);
        $target = Storage::disk($this->targetDisk());

        $rows = $this->backlog();
        $relocated = $failed = $planned = 0;

        foreach ($rows as $row) {
            $path = $row->digital_signature_path;

            // The same refusal the renderer applies. A settings row holding a
            // traversal sequence is not something to copy anywhere.
            if (str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, "\0")) {
                $this->record($row, 'failed', 'unsafe_path');
                $this->warn("  shop {$row->shop_id} unsafe path; skipped.");
                $failed++;
                continue;
            }

            if (! $source->exists($path)) {
                $this->record($row, 'failed', 'source_missing');
                $this->warn("  shop {$row->shop_id} source missing.");
                $failed++;
                continue;
            }

            $sourceDigest = $this->digest($source, $path);
            if ($sourceDigest === null) {
                $this->record($row, 'failed', 'source_unreadable');
                $failed++;
                continue;
            }

            if (! $this->option('execute')) {
                $this->record($row, 'planned', 'would_relocate', $sourceDigest);
                $planned++;
                continue;
            }

            if (! $this->copyVerified($source, $target, $path, $sourceDigest)) {
                $target->delete($path);
                $this->record($row, 'failed', 'copy_verification_failed', $sourceDigest);
                $this->warn("  shop {$row->shop_id} copy did not verify.");
                $failed++;
                continue;
            }

            // Guarded on the values we read, so a concurrent re-upload through
            // SettingsController is not clobbered. Both columns move together —
            // the table's both-or-neither CHECK would reject anything else.
            $updated = DB::table('shop_billing_settings')
                ->where('id', $row->id)
                ->where('digital_signature_disk', self::SOURCE_DISK)
                ->where('digital_signature_path', $path)
                ->update(['digital_signature_disk' => $this->targetDisk()]);

            if ($updated !== 1) {
                $this->record($row, 'failed', 'row_changed_concurrently', $sourceDigest);
                $this->warn("  shop {$row->shop_id} row changed during relocation; left alone.");
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
            $this->warn("  shop {$failure['shop_id']} {$failure['reason']}");
        }

        return $result['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Superseded signature versions: files on the public tree that no settings
     * row names. They are referenced only by immutable snapshots, so they still
     * have to reach the private disk eventually — but this pass only REPORTS
     * them. Deciding which snapshot references which orphan is a reconciliation
     * question, and a command that guessed would be the fastest way to destroy
     * the evidence this finding exists to protect.
     *
     * ponytail: report-only. Upgrade to a move when (and only when) a
     * snapshot-reference index exists to drive it.
     */
    private function orphanPass(): int
    {
        $source = Storage::disk(self::SOURCE_DISK);

        $named = DB::table('shop_billing_settings')
            ->whereNotNull('digital_signature_path')
            ->pluck('digital_signature_path')
            ->flip();

        $shopFilter = $this->option('shop') ? 'signatures/'.(int) $this->option('shop') : 'signatures';
        $orphans = 0;

        foreach ($source->allFiles($shopFilter) as $file) {
            if ($named->has($file)) {
                continue;
            }

            $orphans++;
            $this->line(sprintf('  orphan: %s (%d bytes)', $file, (int) $source->size($file)));
            $this->manifest[] = [
                'path' => $file,
                'action' => 'reported',
                'reason' => 'superseded_version_not_named_by_settings',
                'at' => now()->toIso8601String(),
            ];
        }

        $this->line(sprintf(
            '%d superseded signature version(s) on the public tree. Reported only; nothing moved or deleted.',
            $orphans
        ));
        $this->line('These remain publicly exposed until edge/origin containment covers them.');

        return self::SUCCESS;
    }

    private function purgePass(): int
    {
        $result = $this->verifyRelocated();

        if ($result['failures'] !== []) {
            $this->error(sprintf(
                'Refusing to purge: %d signature(s) failed verification. No original was deleted.',
                count($result['failures'])
            ));

            return self::FAILURE;
        }

        // A purge is only survivable because the renderer falls back across
        // ALLOWED_DISKS for a recorded path (G-19). Refuse to run at all if that
        // property has been removed, rather than discovering it one reprint at
        // a time after the bytes are gone.
        if (! in_array($this->targetDisk(), InvoiceSignatureRenderer::ALLOWED_DISKS, true)
            || ! in_array(self::SOURCE_DISK, InvoiceSignatureRenderer::ALLOWED_DISKS, true)) {
            $this->error('Renderer no longer resolves across both disks; purging would strand finalized invoices.');

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

            if ($source->delete($row->digital_signature_path)) {
                $this->record($row, 'purged', 'original_deleted');
                $purged++;
            } else {
                $this->record($row, 'failed', 'original_delete_failed');
            }
        }

        $this->line(sprintf(
            '%s %d original(s) from the web-served tree.',
            $this->option('execute') ? 'Purged' : 'Would purge',
            $purged
        ));
        $this->line('Superseded versions are NOT covered by this pass — see --orphans.');

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- helpers

    /**
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
            $path = $row->digital_signature_path;

            if (! $target->exists($path)) {
                $failures[] = ['shop_id' => (string) $row->shop_id, 'reason' => 'private copy missing'];
                continue;
            }

            if (! $source->exists($path)) {
                $existenceOnly++;
                continue;
            }

            $sourceDigest = $this->digest($source, $path);
            $targetDigest = $this->digest($target, $path);

            if ($sourceDigest === null || $targetDigest === null) {
                $failures[] = ['shop_id' => (string) $row->shop_id, 'reason' => 'unreadable during verification'];
                continue;
            }

            if (! hash_equals($sourceDigest, $targetDigest)) {
                $failures[] = ['shop_id' => (string) $row->shop_id, 'reason' => 'digest mismatch'];
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
            'settings_id' => $row->id,
            'shop_id' => $row->shop_id,
            'path' => $row->digital_signature_path,
            'from_disk' => $row->digital_signature_disk,
            'to_disk' => $this->targetDisk(),
            'source_sha256' => $digest,
            'action' => $action,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ];
    }

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
            '%s/signatures-%s-%s-%s.jsonl',
            self::MANIFEST_DIR,
            $mode,
            $this->option('execute') ? 'execute' : 'dryrun',
            now()->format('Ymd-His-v')
        );

        Storage::disk($this->targetDisk())->put($file, implode("\n", $lines)."\n");

        $this->line("Manifest: {$file} (on the {$this->targetDisk()} disk)");
    }
}

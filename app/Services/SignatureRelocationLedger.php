<?php

namespace App\Services;

use App\Models\SignatureRelocation;
use Illuminate\Support\Facades\Log;

/**
 * Audit finding S3-04 — the single place that writes and reads relocation
 * evidence.
 *
 * Both sides of the invariant live here on purpose. If the command recorded
 * rows one way and the renderer interpreted them another, the mapping would be
 * trustworthy only by coincidence. One class, one shape, one set of rules.
 *
 * THE INVARIANT THIS ENFORCES
 * ---------------------------
 * A historical reference (shop, path, recorded disk) resolves to bytes on a
 * different disk ONLY IF all of the following hold:
 *
 *   1. the path lies under signatures/{shop}/ for the shop doing the asking —
 *      so a corrupted snapshot cannot reach another tenant's directory;
 *   2. a ledger row exists for exactly that (shop, path, source disk) — so an
 *      unrelated file that happens to share the path is not substituted;
 *   3. the bytes found at the destination hash to the digest recorded when the
 *      copy was verified — so an overwrite after the move is caught rather
 *      than printed onto a bill claiming to be the original.
 *
 * Fail any one and the caller reports the signature unavailable. It never
 * guesses, and it never substitutes a different image.
 */
class SignatureRelocationLedger
{
    /**
     * The only direction relocation moves. Recording anything else is refused
     * rather than stored, because a private -> public row would license the
     * renderer to serve a web-exposed copy for a reference that had already
     * been made private.
     */
    public const SOURCE_DISK = 'public';

    /**
     * Record a completed, destination-verified copy.
     *
     * One row per (shop_id, path, source_disk), by unique index. An existing
     * row is EVIDENCE — the digest an immutable snapshot's bytes are verified
     * against — so it is reused only when it vouches for exactly this copy and
     * is otherwise refused, never rewritten (XR-03, second review; an earlier
     * revision replaced its digest, target and size with updateOrCreate).
     * Checked under a row lock: call inside the transaction that relies on it.
     *
     * @throws ConflictingRelocationEvidence
     */
    public function record(
        int $shopId,
        string $path,
        string $sourceDisk,
        string $targetDisk,
        string $sha256,
        int $bytes,
    ): SignatureRelocation {
        if ($sourceDisk !== self::SOURCE_DISK) {
            throw new \InvalidArgumentException(
                "Refusing to record a relocation out of '{$sourceDisk}'. ".
                'Only '.self::SOURCE_DISK.' -> private moves are recordable; the reverse '
                .'would let a private reference resolve to a web-served file.'
            );
        }

        if ($sourceDisk === $targetDisk) {
            throw new \InvalidArgumentException('A relocation must change disk.');
        }

        if (! $this->pathBelongsToShop($path, $shopId)) {
            throw new \InvalidArgumentException(
                'Refusing to record a relocation for a path outside this shop\'s signature directory.'
            );
        }

        $key = ['shop_id' => $shopId, 'path' => $path, 'source_disk' => $sourceDisk];
        $existing = SignatureRelocation::withoutTenant()->where($key)->lockForUpdate()->first();

        if ($existing !== null) {
            if (! $this->vouchesFor($existing, $targetDisk, $sha256, $bytes)) {
                throw new ConflictingRelocationEvidence('Existing relocation evidence records other bytes for this path; it is not rewritten.');
            }

            return $existing;
        }

        return SignatureRelocation::withoutTenant()->create($key + [
            'target_disk'  => $targetDisk,
            'sha256'       => $sha256,
            'bytes'        => $bytes,
            'relocated_at' => now(),
        ]);
    }

    /** Existing evidence for a public reference, if any. Read-only. */
    public function existingFor(int $shopId, string $path): ?SignatureRelocation
    {
        return SignatureRelocation::withoutTenant()
            ->where('shop_id', $shopId)->where('path', $path)->where('source_disk', self::SOURCE_DISK)
            ->first();
    }

    /** Does this evidence describe exactly this copy? */
    public function vouchesFor(SignatureRelocation $evidence, string $targetDisk, string $sha256, int $bytes): bool
    {
        return $evidence->target_disk === $targetDisk
            && hash_equals($evidence->sha256, $sha256)
            && (int) $evidence->bytes === $bytes;
    }

    /**
     * Where a historical reference should now be read from, or null if nothing
     * vouches for a move.
     *
     * Returns the target disk only. The caller still has to read and verify the
     * bytes — see verify() — because a row proves a copy HAPPENED, not that it
     * is still intact.
     */
    public function targetFor(int $shopId, string $path, string $recordedDisk): ?SignatureRelocation
    {
        // One-directional by construction: a reference already recorded as
        // private has no recordable move, so no lookup is even attempted.
        if ($recordedDisk !== self::SOURCE_DISK) {
            return null;
        }

        if (! $this->pathBelongsToShop($path, $shopId)) {
            return null;
        }

        return SignatureRelocation::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('path', $path)
            ->where('source_disk', self::SOURCE_DISK)
            ->first();
    }

    /**
     * Do these bytes match what was recorded when the copy was verified?
     *
     * hash_equals rather than === because this is a digest comparison on a
     * security path; the timing difference is irrelevant here but the habit of
     * writing digest comparisons the other way is not worth keeping.
     */
    public function verify(SignatureRelocation $relocation, string $bytes): bool
    {
        $actual = hash('sha256', $bytes);

        if (! hash_equals($relocation->sha256, $actual)) {
            Log::warning('Signature relocation digest mismatch', [
                'shop_id'     => $relocation->shop_id,
                'source_disk' => $relocation->source_disk,
                'target_disk' => $relocation->target_disk,
                // Hashes, never the path: this is a diagnostics surface and a
                // path names a file in a shop's private storage.
                'path_hash'   => substr(hash('sha256', $relocation->path), 0, 12),
                'expected'    => substr($relocation->sha256, 0, 12),
                'actual'      => substr($actual, 0, 12),
            ]);

            return false;
        }

        return true;
    }

    /**
     * May this shop's reference name this path?
     *
     * CORRECTED, AND THE CORRECTION MATTERS
     * -------------------------------------
     * The first version of this check required signatures/{shop_id}/ and
     * nothing else. That would have refused EVERY signature already in
     * production. Baseline 018b3d8 SettingsController::update():527 stores
     * uploads with the directory literal 'signatures' — flat, with no shop
     * segment at all — so every existing path is signatures/{ULID}.{ext}.
     * Only SignatureStore, added by this work, writes the shop-scoped form.
     * Test G-11 caught this; it was a real regression, not a test artifact.
     *
     * Worth recording separately: that flat layout means every shop's signature
     * shares one directory on the public tree, so the paths are mutually
     * enumerable. That is part of S3-04's exposure, not something this check
     * can fix — it is noted here so the layout is not mistaken for harmless.
     *
     * THE RULE
     *   - must sit under signatures/          (it is a signature at all)
     *   - a SHOP-SCOPED path (signatures/{digits}/...) must name THIS shop.
     *     This is the form all new uploads take, so the boundary tightens
     *     automatically as the estate turns over.
     *   - a LEGACY FLAT path (signatures/{file}) carries no ownership claim in
     *     the string, so none is inferred from it. It is allowed here, and the
     *     tenant boundary for it is enforced where the evidence actually lives:
     *     targetFor() will only follow a relocation whose ledger row is keyed
     *     to this shop_id. A flat path therefore still cannot be followed
     *     across disks on another shop's evidence.
     *
     * The renderer refuses traversal sequences, absolute paths and NUL bytes
     * BEFORE calling this, so '..' cannot satisfy the prefix and then climb out.
     */
    public function pathBelongsToShop(string $path, int $shopId): bool
    {
        if (! str_starts_with($path, 'signatures/')) {
            return false;
        }

        $remainder = substr($path, strlen('signatures/'));
        $slash     = strpos($remainder, '/');

        // Flat legacy path: no directory segment to check against.
        if ($slash === false) {
            return $remainder !== '';
        }

        $segment = substr($remainder, 0, $slash);

        // A non-numeric first segment is not a shop directory and is not a
        // shape anything in this application produces. Refuse rather than
        // interpret it.
        if ($segment === '' || ! ctype_digit($segment)) {
            return false;
        }

        return (int) $segment === $shopId;
    }
}

final class ConflictingRelocationEvidence extends \RuntimeException {}

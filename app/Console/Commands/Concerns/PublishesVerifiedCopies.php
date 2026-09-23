<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Filesystem\FilesystemAdapter;
use Throwable;

/**
 * XR-03 — publish a verified copy without ever destroying what is already at
 * the destination. Shared by the karigar, purchase and signature movers.
 *
 *   absent      copied to a temporary name this mover owns, verified, then
 *               published with link(), which fails rather than replace an
 *               existing file — atomic and no-clobber
 *   identical   left exactly as it is; the caller resumes (a previous run
 *               published it and stopped before recording that)
 *   conflict    a different file is already there: nothing is written or
 *               deleted, and the caller changes no metadata
 *
 * The only file this ever deletes is its own temporary, whose name carries 64
 * random bits. Another mover's temporary is never touched, so a stray one
 * from a crashed run stays until someone removes it deliberately.
 *
 * Supported concurrency contract: any number of movers may run at once
 * against the same rows. Each publishes through its own temporary; the first
 * link() wins, and every other sees an identical destination and resumes.
 * Local disks only — link() needs one filesystem. On any other adapter the
 * link fails and the row is reported, never overwritten.
 */
trait PublishesVerifiedCopies
{
    /** @return 'published'|'identical'|'conflict'|'failed' */
    protected function publishVerifiedCopy(FilesystemAdapter $source, FilesystemAdapter $target, string $path, string $expectedDigest): string
    {
        $state = $this->destinationState($target, $path, $expectedDigest);
        if ($state !== null) {
            return $state;
        }

        $temp = $path.'.relocating-'.bin2hex(random_bytes(8));

        try {
            $stream = $source->readStream($path);
            if (! is_resource($stream)) {
                return 'failed';
            }

            try {
                $written = $target->writeStream($temp, $stream);
            } catch (Throwable) {
                $written = false;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $actual = $written === true ? $this->digest($target, $temp) : null;
            if ($actual === null || ! hash_equals($expectedDigest, $actual)) {
                return 'failed';
            }

            if (@link($target->path($temp), $target->path($path))) {
                return 'published';
            }

            // Someone published first — a concurrent mover — or link() is not
            // available here. Judge what is there now; never overwrite it.
            return $this->destinationState($target, $path, $expectedDigest) ?? 'failed';
        } finally {
            try {
                if ($target->exists($temp)) {
                    $target->delete($temp);
                }
            } catch (Throwable) {
                // Left in place: an unpublished temporary is inert.
            }
        }
    }

    /** null when nothing is at the destination. */
    private function destinationState(FilesystemAdapter $target, string $path, string $expectedDigest): ?string
    {
        if (! $target->exists($path)) {
            return null;
        }

        $existing = $this->digest($target, $path);

        // An unreadable existing file is never overwritten either.
        return $existing !== null && hash_equals($expectedDigest, $existing) ? 'identical' : 'conflict';
    }
}

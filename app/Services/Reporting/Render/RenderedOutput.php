<?php

namespace App\Services\Reporting\Render;

use Illuminate\Support\Facades\Storage;

/**
 * The in-memory result of a file renderer (frozen §3.1, §5). Phase 0 renderers
 * produce the whole payload as bytes in memory; persistence (download disk,
 * queued-export artifact) is a concern of a later phase. This is an immutable
 * value object — the renderer hands back exactly the filename, MIME type, and
 * bytes; nothing downstream mutates it.
 */
final class RenderedOutput
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly string $contents,
    ) {
    }

    /** Byte length of the payload — handy for size logging / audit. */
    public function byteSize(): int
    {
        return strlen($this->contents);
    }

    /**
     * Persist the payload to a Laravel disk and return the stored path.
     * Convenience for the later download/queued path; the in-memory
     * `$contents` remains the primary artifact.
     */
    public function storeOn(string $disk, ?string $directory = null): string
    {
        $path = $directory !== null && $directory !== ''
            ? rtrim($directory, '/') . '/' . $this->filename
            : $this->filename;

        Storage::disk($disk)->put($path, $this->contents);

        return $path;
    }
}

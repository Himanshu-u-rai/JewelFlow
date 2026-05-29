<?php

namespace App\Services\Mobile;

use App\Models\PendingUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mobile upload lifecycle service (M6).
 *
 * Three-step flow:
 *   1. mintIntent()  — client declares intent (kind, content_type, size).
 *                      Server returns an upload_token + upload_url.
 *   2. store()       — client PUT-streams the bytes to /uploads/{token}.
 *                      Server writes original, verifies MIME, generates thumbnail.
 *   3. consume()     — Item / Repair create links the ready upload to the entity.
 *
 * All disk writes use the 'public' disk to match the existing item-image
 * convention (Storage::disk('public')).
 *
 * Drift note: this service NEVER reads pricing, metal rates, or vault data.
 * It is a pure media-lifecycle service.
 */
class UploadIntentService
{
    public const MAX_SIZE_BYTES = 5_242_880; // 5 MB

    public const ALLOWED_KINDS = ['item_image', 'repair_image'];

    public const ALLOWED_CONTENT_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public const THUMBNAIL_SIZE = 512; // px, square max dimension

    /**
     * Issue a new upload intent.
     *
     * @throws \InvalidArgumentException on bad kind/type/size
     */
    public function mintIntent(
        int    $shopId,
        int    $userId,
        string $kind,
        string $contentType,
        int    $sizeBytes
    ): PendingUpload {
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new \InvalidArgumentException(
                "Upload kind '{$kind}' is not supported. Allowed: " . implode(', ', self::ALLOWED_KINDS)
            );
        }

        if (! array_key_exists($contentType, self::ALLOWED_CONTENT_TYPES)) {
            throw new \InvalidArgumentException(
                "Content type '{$contentType}' is not supported. Allowed: " . implode(', ', array_keys(self::ALLOWED_CONTENT_TYPES))
            );
        }

        if ($sizeBytes > self::MAX_SIZE_BYTES) {
            throw new \InvalidArgumentException(
                "File size {$sizeBytes} exceeds the maximum of " . self::MAX_SIZE_BYTES . " bytes."
            );
        }

        if ($sizeBytes <= 0) {
            throw new \InvalidArgumentException('Declared size_bytes must be greater than zero.');
        }

        return PendingUpload::create([
            'shop_id'             => $shopId,
            'user_id'             => $userId,
            'kind'                => $kind,
            'content_type'        => $contentType,
            'declared_size_bytes' => $sizeBytes,
            'upload_token'        => Str::random(48),
            'status'              => 'pending',
            'expires_at'          => now()->addMinutes(15),
        ]);
    }

    /**
     * Store raw bytes from the PUT request, verify the image, generate thumbnail.
     *
     * Returns the updated PendingUpload (status = 'ready' on success).
     */
    public function store(PendingUpload $upload, string $rawBytes): PendingUpload
    {
        if ($upload->status !== 'pending') {
            throw new \LogicException("Upload {$upload->upload_token} is not in 'pending' state (current: {$upload->status}).");
        }

        if ($upload->isExpired()) {
            $upload->update(['status' => 'expired']);
            throw new \RuntimeException('Upload token has expired.');
        }

        $ext        = self::ALLOWED_CONTENT_TYPES[$upload->content_type] ?? 'jpg';
        $token      = $upload->upload_token;
        $shopId     = $upload->shop_id;
        $origPath   = "uploads/originals/{$shopId}/{$token}.{$ext}";
        $thumbPath  = "uploads/thumbnails/{$shopId}/{$token}.webp";

        // Write original bytes to disk first so finfo can read the actual file.
        Storage::disk('public')->put($origPath, $rawBytes);
        $fullOrigPath = Storage::disk('public')->path($origPath);

        // Verify MIME type from actual file content (defence in depth).
        $actualMime = $this->detectMime($fullOrigPath);
        if (! array_key_exists($actualMime, self::ALLOWED_CONTENT_TYPES)) {
            Storage::disk('public')->delete($origPath);
            $upload->update(['status' => 'failed']);
            throw new \RuntimeException("Uploaded file has unsupported or mismatched MIME type: {$actualMime}.");
        }

        // Generate thumbnail via GD.
        try {
            $this->generateThumbnail($fullOrigPath, $actualMime, $thumbPath);
        } catch (\Throwable $e) {
            Log::warning('M6 thumbnail generation failed — upload still marked ready.', [
                'upload_token' => $token,
                'error'        => $e->getMessage(),
            ]);
            $thumbPath = null;
        }

        $upload->update([
            'original_path'    => $origPath,
            'thumbnail_path'   => $thumbPath,
            'actual_size_bytes' => strlen($rawBytes),
            'status'           => 'ready',
        ]);

        return $upload->fresh();
    }

    /**
     * Link a ready upload to an entity (Item, Repair, etc.) and mark it consumed.
     */
    public function consume(PendingUpload $upload, Model $consumer): void
    {
        if ($upload->status !== 'ready') {
            throw new \LogicException("Upload must be in 'ready' state to consume. Current: {$upload->status}.");
        }

        if ($upload->consumed_at !== null) {
            throw new \LogicException("Upload {$upload->upload_token} has already been consumed.");
        }

        $upload->update([
            'consumed_at'      => now(),
            'consumed_by_type' => get_class($consumer),
            'consumed_by_id'   => $consumer->getKey(),
        ]);
    }

    /**
     * Return the public URL for the original or thumbnail of a ready upload.
     */
    public function originalUrl(PendingUpload $upload): ?string
    {
        if (! $upload->original_path) {
            return null;
        }
        return Storage::disk('public')->url($upload->original_path);
    }

    public function thumbnailUrl(PendingUpload $upload): ?string
    {
        if (! $upload->thumbnail_path) {
            return null;
        }
        return Storage::disk('public')->url($upload->thumbnail_path);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────

    private function detectMime(string $filePath): string
    {
        if (! function_exists('finfo_open')) {
            // Fallback: get the extension the file was written with and
            // reverse-map it. This is only reached on environments without
            // the fileinfo extension, which is not expected in production.
            $ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $flip = array_flip(self::ALLOWED_CONTENT_TYPES);
            return $flip[$ext] ?? 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        return (string) $mime;
    }

    private function generateThumbnail(string $sourcePath, string $mime, string $destRelPath): void
    {
        // Load via GD — available in Laravel's standard PHP image.
        $src = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/png'  => imagecreatefrompng($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            default      => throw new \RuntimeException("GD cannot load MIME type: {$mime}"),
        };

        if ($src === false) {
            throw new \RuntimeException('GD failed to load the source image.');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $max  = self::THUMBNAIL_SIZE;

        // Scale while preserving aspect ratio.
        if ($srcW <= $max && $srcH <= $max) {
            $dstW = $srcW;
            $dstH = $srcH;
        } elseif ($srcW > $srcH) {
            $dstW = $max;
            $dstH = (int) round($srcH * $max / $srcW);
        } else {
            $dstH = $max;
            $dstW = (int) round($srcW * $max / $srcH);
        }

        $dst = imagecreatetruecolor($dstW, $dstH);

        // Preserve transparency for PNG/WebP.
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        // Write to a temp buffer then push to disk as WebP.
        ob_start();
        imagewebp($dst, null, 82);
        $webpBytes = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        Storage::disk('public')->put($destRelPath, $webpBytes);
    }
}

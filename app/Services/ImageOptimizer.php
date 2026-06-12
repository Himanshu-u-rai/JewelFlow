<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Converts uploaded raster images to resized, compressed WebP on the way to
 * storage — saves disk space and speeds up image loads on web + mobile.
 *
 * Safety: non-raster files (PDF, SVG, unknown) and ANY conversion failure fall
 * back to storing the original untouched, so an upload can never break because
 * of optimisation. Uses GD (already enabled); no external dependency.
 */
class ImageOptimizer
{
    /** Longest edge, in px. Downscale only — never upscale. */
    public const MAX_EDGE = 1600;

    /** WebP quality. ~88 is visually lossless for jewellery (keeps shine/sparkle). */
    public const WEBP_QUALITY = 88;

    private const RASTER_MIMES = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
    ];

    /**
     * Optimise an uploaded image to WebP and store it; return the stored path.
     * Non-images and failures store the original unchanged.
     */
    public function optimizeAndStore(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $directory = trim($directory, '/');

        if (! $this->canOptimize($file)) {
            return $file->store($directory, $disk);
        }

        try {
            $binary = $this->encodeUploadedFile($file);
        } catch (\Throwable $e) {
            Log::warning('ImageOptimizer: storing original (' . $e->getMessage() . ')');
            return $file->store($directory, $disk);
        }

        return $this->putWebp($binary, $directory, $disk);
    }

    /**
     * Same as optimizeAndStore but for raw image bytes (e.g. mobile base64
     * uploads). On any failure the original bytes are stored with the supplied
     * fallback extension.
     */
    public function optimizeAndStoreBinary(string $binary, string $directory, string $disk, string $fallbackExtension): string
    {
        $directory = trim($directory, '/');

        if (function_exists('imagewebp')) {
            try {
                $image = @imagecreatefromstring($binary);
                if ($image !== false) {
                    return $this->putWebp($this->gdToWebp($image), $directory, $disk);
                }
            } catch (\Throwable $e) {
                Log::warning('ImageOptimizer(binary): storing original (' . $e->getMessage() . ')');
            }
        }

        $path = $directory . '/' . Str::ulid()->toBase32() . '.' . ltrim($fallbackExtension, '.');
        Storage::disk($disk)->put($path, $binary);

        return $path;
    }

    private function putWebp(string $binary, string $directory, string $disk): string
    {
        $path = $directory . '/' . Str::ulid()->toBase32() . '.webp';
        Storage::disk($disk)->put($path, $binary);

        return $path;
    }

    private function canOptimize(UploadedFile $file): bool
    {
        return function_exists('imagewebp')
            && in_array(strtolower((string) $file->getMimeType()), self::RASTER_MIMES, true);
    }

    private function encodeUploadedFile(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();
        $contents = $realPath ? @file_get_contents($realPath) : false;
        if ($contents === false) {
            throw new \RuntimeException('unreadable upload');
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw new \RuntimeException('unsupported or corrupt image');
        }

        // EXIF orientation (phone JPEGs) before resize/encode.
        $image = $this->applyExifOrientation($image, $realPath, $file);

        return $this->gdToWebp($image);
    }

    /**
     * Downscale (if needed) and WebP-encode a GD image. Always destroys the
     * image it ends with, so callers must not reuse it afterwards.
     */
    private function gdToWebp(\GdImage $image): string
    {
        $image = $this->downscale($image);

        try {
            if (! imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }
            // Preserve transparency (PNG -> WebP keeps alpha).
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $ok = imagewebp($image, null, self::WEBP_QUALITY);
            $binary = (string) ob_get_clean();

            if (! $ok || $binary === '') {
                throw new \RuntimeException('webp encode failed');
            }

            return $binary;
        } finally {
            imagedestroy($image);
        }
    }

    private function downscale(\GdImage $image): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $longest = max($w, $h);

        if ($longest <= self::MAX_EDGE) {
            return $image; // already within bounds — never upscale
        }

        $scale = self::MAX_EDGE / $longest;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($image);

        return $resized;
    }

    /**
     * Apply EXIF orientation so phone photos aren't stored sideways. JPEG only;
     * other formats don't carry an EXIF orientation tag.
     */
    private function applyExifOrientation(\GdImage $image, ?string $realPath, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! $realPath) {
            return $image;
        }
        if (! in_array(strtolower((string) $file->getMimeType()), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        try {
            $exif = @exif_read_data($realPath);
            $orientation = (int) ($exif['Orientation'] ?? 0);
        } catch (\Throwable $e) {
            return $image;
        }

        $rotate = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($rotate !== 0) {
            $rotated = imagerotate($image, $rotate, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                return $rotated;
            }
        }

        return $image;
    }
}

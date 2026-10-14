<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CustomerProfilePhotoEncoder
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PIXELS = 20_000_000;

    /** @return array{bytes:string,width:int,height:int,quality:int} */
    public function encode(UploadedFile $file): array
    {
        if (! $file->isValid() || ! $file->getSize() || $file->getSize() > self::MAX_BYTES) {
            $this->reject('Choose an image no larger than 5 MB.');
        }
        $info = @getimagesize($file->getPathname());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $file->getMimeType() !== $info['mime']) {
            $this->reject('Choose a JPEG, PNG or WebP image. HEIC, GIF, SVG and documents are not supported.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            $this->reject('Choose an image of no more than 20 megapixels.');
        }
        // libjpeg decodes a cut-off JPEG without complaint and greys out the rest.
        if ($info[2] === IMAGETYPE_JPEG && ! $this->jpegIsComplete((string) file_get_contents($file->getPathname()))) {
            $this->reject('This photo looks incomplete or damaged, so it was not saved. Your existing photo is unchanged. Choose the photo again or take a new one.');
        }
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($memoryLimit > 0 && memory_get_usage(true) + $info[0] * $info[1] * 8 + 16 * 1024 * 1024 > $memoryLimit) {
            $this->reject('This image needs more processing memory. Choose a smaller image or take another photo.');
        }

        $image = null;
        $resized = null;
        try {
            if (! function_exists('imagewebp') || ! function_exists('exif_read_data')) {
                throw new RuntimeException('Image processing is unavailable.');
            }
            $contents = file_get_contents($file->getPathname());
            $image = @imagecreatefromstring($contents);
            if (! $image) {
                throw new RuntimeException('The image could not be decoded.');
            }
            $image = $this->orient($image, $this->orientation($file, $info[2], $contents));
            $scale = min(1, 512 / max(imagesx($image), imagesy($image)));
            $width = max(1, (int) round(imagesx($image) * $scale));
            $height = max(1, (int) round(imagesy($image) * $scale));
            $resized = imagecreatetruecolor($width, $height);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
            if (! imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image))) {
                throw new RuntimeException('The image could not be resized.');
            }
            // Target size is advisory: do not sacrifice identifying detail below quality 70.
            foreach ([80, 75, 70] as $quality) {
                ob_start();
                try {
                    $ok = imagewebp($resized, null, $quality);
                    $bytes = (string) ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if (! $ok || ! $bytes || ! @getimagesizefromstring($bytes)) {
                    throw new RuntimeException('The image could not be compressed.');
                }
                if (strlen($bytes) <= 100 * 1024) {
                    break;
                }
            }

            return compact('bytes', 'width', 'height', 'quality');
        } catch (Throwable $e) {
            $this->reject('This photo could not be processed. Your existing photo is unchanged. Choose another JPEG, PNG or WebP image.');
        } finally {
            if ($image instanceof GdImage) {
                imagedestroy($image);
            }
            if ($resized instanceof GdImage) {
                imagedestroy($resized);
            }
        }
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated) {
            throw new RuntimeException('The image could not be oriented.');
        }
        imagedestroy($image);

        return $rotated;
    }

    private function orientation(UploadedFile $file, int $type, string $contents): int
    {
        if ($type === IMAGETYPE_JPEG) {
            $exif = @exif_read_data($file->getPathname(), 'IFD0');
            return (int) ($exif['Orientation'] ?? 1);
        }
        // PNG/WebP contain TIFF EXIF chunks; let PHP's EXIF reader interpret them.
        $png = $type === IMAGETYPE_PNG;
        $offset = $png ? 8 : 12;
        $size = strlen($contents);
        while ($offset + 8 <= $size) {
            $length = unpack($png ? 'N' : 'V', substr($contents, $offset + ($png ? 0 : 4), 4))[1];
            $chunk = substr($contents, $offset + ($png ? 4 : 0), 4);
            $padding = $png ? 4 : $length % 2;
            if ($length > $size - $offset - 8 - $padding) throw new RuntimeException('Invalid image metadata chunk.');
            if ($chunk === ($png ? 'eXIf' : 'EXIF')) {
                $tiff = substr($contents, $offset + 8, $length);
                if (str_starts_with($tiff, "Exif\0\0")) $tiff = substr($tiff, 6);
                $stream = fopen('php://memory', 'w+b');
                if (! $stream) throw new RuntimeException('Image metadata could not be read.');
                try {
                    if (fwrite($stream, $tiff) !== strlen($tiff)) throw new RuntimeException('Image metadata could not be read.');
                    rewind($stream);
                    $exif = @exif_read_data($stream, 'IFD0');
                    return (int) ($exif['Orientation'] ?? 1);
                } finally {
                    fclose($stream);
                }
            }
            $offset += 8 + $length + $padding;
        }
        return 1;
    }

    /**
     * Walks the JPEG marker structure: segments by their lengths (so an EXIF
     * thumbnail's own end marker is skipped with its segment), entropy-coded
     * data up to the next real marker, every scan of a progressive image, until
     * the end-of-image marker. Bytes after it (a Motion Photo's video, padding)
     * are not part of the image and are ignored.
     */
    private function jpegIsComplete(string $bytes): bool
    {
        $size = strlen($bytes);
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return false;
        }
        $offset = 2;
        $scans = 0;
        while ($offset + 2 <= $size) {
            if ($bytes[$offset] !== "\xFF") {
                return false;
            }
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xFF) { // fill byte
                $offset++;
                continue;
            }
            if ($marker === 0xD9) {
                return $scans > 0;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $offset += 2;
                continue;
            }
            if ($offset + 4 > $size) {
                return false;
            }
            $length = unpack('n', substr($bytes, $offset + 2, 2))[1];
            if ($length < 2 || $offset + 2 + $length > $size) {
                return false;
            }
            $offset += 2 + $length;
            if ($marker !== 0xDA) {
                continue;
            }
            $scans++;
            // Entropy-coded data: FF00 is a stuffed byte and FFD0-FFD7 restart markers; any other FFxx ends the scan.
            while (true) {
                $next = strpos($bytes, "\xFF", $offset);
                if ($next === false || $next + 1 >= $size) {
                    return false;
                }
                $code = ord($bytes[$next + 1]);
                if ($code === 0x00 || ($code >= 0xD0 && $code <= 0xD7)) {
                    $offset = $next + 2;
                } elseif ($code === 0xFF) {
                    $offset = $next + 1;
                } else {
                    $offset = $next;
                    break;
                }
            }
        }

        return false;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['customer_photo' => $message]);
    }
}

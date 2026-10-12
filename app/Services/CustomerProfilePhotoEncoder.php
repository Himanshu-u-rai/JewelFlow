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

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['customer_photo' => $message]);
    }
}

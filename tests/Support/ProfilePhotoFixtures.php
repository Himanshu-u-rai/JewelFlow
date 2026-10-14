<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

final class ProfilePhotoFixtures
{
    public static function portrait(int $width = 1600, int $height = 1200, string $format = 'jpeg'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 225, 240, 235));
        imagefilledellipse($image, (int) ($width / 2), (int) ($height * .45), (int) ($width * .28), (int) ($height * .55), imagecolorallocate($image, 206, 149, 104));
        imagefilledellipse($image, (int) ($width / 2), (int) ($height * .95), (int) ($width * .7), (int) ($height * .5), imagecolorallocate($image, 16, 90, 100));
        foreach ([.45, .55] as $x) {
            imagefilledellipse($image, (int) ($width * $x), (int) ($height * .4), max(2, (int) ($width * .02)), max(2, (int) ($height * .025)), imagecolorallocate($image, 30, 30, 30));
        }
        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 95),
            default => imagejpeg($image, null, 95),
        };
        $bytes = ob_get_clean();
        imagedestroy($image);

        return self::fromBytes($bytes, 'synthetic-portrait.'.($format === 'jpeg' ? 'jpg' : $format));
    }

    public static function oriented(int $orientation, string $format = 'jpeg'): UploadedFile
    {
        $image = imagecreatetruecolor(120, 80);
        foreach ([[0, 0, 59, 39, 255, 0, 0], [60, 0, 119, 39, 0, 255, 0], [0, 40, 59, 79, 0, 0, 255], [60, 40, 119, 79, 255, 255, 0]] as $r) {
            imagefilledrectangle($image, $r[0], $r[1], $r[2], $r[3], imagecolorallocate($image, $r[4], $r[5], $r[6]));
        }
        ob_start();
        match ($format) {
            'png' => imagepng($image), 'webp' => imagewebp($image, null, 100), default => imagejpeg($image, null, 100),
        };
        $encoded = ob_get_clean();
        imagedestroy($image);
        // A real little-endian EXIF directory with orientation and GPS latitude.
        $tiff = "II\x2a\x00".pack('V', 8).pack('v', 2)
            .pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\0\0"
            .pack('vvVV', 0x8825, 4, 1, 38).pack('V', 0)
            .pack('v', 2).pack('vvV', 1, 2, 2)."N\0\0\0"
            .pack('vvVV', 2, 5, 3, 68).pack('V', 0)
            .pack('VVVVVV', 18, 1, 30, 1, 0, 1);
        $payload = "Exif\0\0".$tiff;
        if ($format === 'png') {
            $chunk = pack('N', strlen($tiff)).'eXIf'.$tiff.pack('N', crc32('eXIf'.$tiff));
            $encoded = substr($encoded, 0, 33).$chunk.substr($encoded, 33);
        } elseif ($format === 'webp') {
            $body = 'WEBPVP8X'.pack('V', 10)."\x08\0\0\0".substr(pack('V', 119), 0, 3).substr(pack('V', 79), 0, 3)
                .substr($encoded, 12).'EXIF'.pack('V', strlen($tiff)).$tiff.(strlen($tiff) % 2 ? "\0" : '');
            $encoded = 'RIFF'.pack('V', strlen($body)).$body;
        } else {
            $encoded = substr($encoded, 0, 2)."\xff\xe1".pack('n', strlen($payload) + 2).$payload.substr($encoded, 2);
        }

        return self::fromBytes($encoded, 'synthetic-oriented.'.($format === 'jpeg' ? 'jpg' : $format));
    }

    /** JPEG bytes of the synthetic portrait; progressive when asked. */
    public static function jpegBytes(int $width = 800, int $height = 600, bool $progressive = false): string
    {
        $image = imagecreatefromstring(file_get_contents(($file = self::portrait($width, $height))->getPathname()));
        unlink($file->getPathname());
        imageinterlace($image, $progressive);
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return ob_get_clean();
    }

    /** Inserts an APP segment right after SOI. */
    public static function withSegment(string $jpeg, int $marker, string $payload): string
    {
        return substr($jpeg, 0, 2)."\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    /**
     * A Motion Photo as phone cameras write it: XMP declaring the embedded video,
     * then the MP4 appended AFTER the JPEG's end-of-image marker. The video bytes
     * deliberately contain JPEG-looking markers.
     */
    public static function motionPhoto(): string
    {
        $video = pack('N', 24).'ftypmp42'.pack('N', 0).'isommp42'
            .pack('N', 40).'mdat'."\xFF\xD8\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00".str_repeat("\x12\xFF\x00\x34", 6);
        $xmp = 'http://ns.adobe.com/xap/1.0/'."\0"
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description xmlns:GCamera="http://ns.google.com/photos/1.0/camera/" GCamera:MotionPhoto="1" GCamera:MotionPhotoVersion="1"'
            .' GCamera:MotionPhotoPresentationTimestampUs="0"/>'
            .'<rdf:Description xmlns:Container="http://ns.google.com/photos/1.0/container/" xmlns:Item="http://ns.google.com/photos/1.0/container/item/">'
            .'<Container:Directory><rdf:Seq><rdf:li rdf:parseType="Resource"><Container:Item Item:Mime="image/jpeg" Item:Semantic="Primary"/></rdf:li>'
            .'<rdf:li rdf:parseType="Resource"><Container:Item Item:Mime="video/mp4" Item:Semantic="MotionPhoto" Item:Length="'.strlen($video).'"/></rdf:li>'
            .'</rdf:Seq></Container:Directory></rdf:Description></rdf:RDF></x:xmpmeta>';

        return self::withSegment(self::jpegBytes(), 0xE1, $xmp).$video;
    }

    /** EXIF APP1 that carries a complete embedded thumbnail JPEG (its own SOI..EOI). */
    public static function withExifThumbnail(string $jpeg): string
    {
        return self::withSegment($jpeg, 0xE1, "Exif\0\0II\x2a\x00".pack('V', 8).pack('v', 0).pack('V', 0).self::jpegBytes(64, 48));
    }

    public static function fromBytes(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'jf-photo-');
        register_shutdown_function(static function () use ($path) {
            if (is_file($path)) unlink($path);
        });
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }
}

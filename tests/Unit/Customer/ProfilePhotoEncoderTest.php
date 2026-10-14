<?php

namespace Tests\Unit\Customer;

use App\Services\CustomerProfilePhotoEncoder;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Support\ProfilePhotoFixtures;

class ProfilePhotoEncoderTest extends TestCase
{
    private function encoder(): CustomerProfilePhotoEncoder
    {
        $this->assertTrue(class_exists(CustomerProfilePhotoEncoder::class), 'The strict profile-photo encoder is not implemented yet.');

        return new CustomerProfilePhotoEncoder;
    }

    public static function formats(): array
    {
        return [['jpeg'], ['png'], ['webp']];
    }

    #[DataProvider('formats')]
    public function test_all_supported_inputs_become_one_proportional_webp(string $format): void
    {
        $encoder = $this->encoder();
        $upload = ProfilePhotoFixtures::portrait(1600, 1200, $format);
        $result = $encoder->encode($upload);
        $info = getimagesizefromstring($result['bytes']);
        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame([512, 384], [$info[0], $info[1]]);
        $this->assertSame(512, $result['width']);
        $this->assertSame(384, $result['height']);
        $this->assertLessThanOrEqual(100 * 1024, strlen($result['bytes']));
        $this->assertLessThan($upload->getSize(), strlen($result['bytes']));
        unlink($upload->getPathname());
    }

    public function test_small_images_are_not_upscaled(): void
    {
        $upload = ProfilePhotoFixtures::portrait(80, 120, 'png');
        $info = getimagesizefromstring($this->encoder()->encode($upload)['bytes']);
        $this->assertSame([80, 120], [$info[0], $info[1]]);
        unlink($upload->getPathname());
    }

    public static function orientations(): array
    {
        return [[1, ['R', 'G', 'B', 'Y']], [2, ['G', 'R', 'Y', 'B']],
            [3, ['Y', 'B', 'G', 'R']], [4, ['B', 'Y', 'R', 'G']],
            [5, ['R', 'B', 'G', 'Y']], [6, ['B', 'R', 'Y', 'G']],
            [7, ['Y', 'G', 'B', 'R']], [8, ['G', 'Y', 'R', 'B']]];
    }

    #[DataProvider('orientations')]
    public function test_orientation_and_mirrors_are_corrected_and_gps_stripped(int $orientation, array $corners): void
    {
        $encoder = $this->encoder();
        $upload = ProfilePhotoFixtures::oriented($orientation);
        $exif = exif_read_data($upload->getPathname(), null, true);
        $this->assertSame($orientation, $exif['IFD0']['Orientation']);
        $this->assertArrayHasKey('GPS', $exif, 'Fixture must actually contain GPS metadata.');
        $result = $encoder->encode($upload);
        $this->assertStringNotContainsString('Exif', $result['bytes']);
        $this->assertStringNotContainsString('EXIF', $result['bytes']);
        $this->assertStringNotContainsString('XMP ', $result['bytes']);
        $image = imagecreatefromstring($result['bytes']);
        $expectedSize = $orientation >= 5 ? [80, 120] : [120, 80];
        $this->assertSame($expectedSize, [imagesx($image), imagesy($image)]);
        $colors = ['R' => [255, 0, 0], 'G' => [0, 255, 0], 'B' => [0, 0, 255], 'Y' => [255, 255, 0]];
        foreach ([[10, 10], [imagesx($image) - 11, 10], [10, imagesy($image) - 11], [imagesx($image) - 11, imagesy($image) - 11]] as $i => [$x, $y]) {
            $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            foreach (['red', 'green', 'blue'] as $c => $channel) {
                $this->assertEqualsWithDelta($colors[$corners[$i]][$c], $rgb[$channel], 25);
            }
        }
        imagedestroy($image);
        unlink($upload->getPathname());
    }

    public static function badFiles(): array
    {
        return [['<svg><script>alert(1)</script></svg>', 'fake.jpg', 'JPEG, PNG or WebP'],
            ['not an image', 'photo.png', 'JPEG, PNG or WebP'],
            ["GIF89a\x01\0\x01\0", 'photo.gif', 'JPEG, PNG or WebP'],
            [str_repeat('x', 5 * 1024 * 1024 + 1), 'big.jpg', '5 MB']];
    }

    #[DataProvider('badFiles')]
    public function test_invalid_content_or_size_is_rejected_without_an_original_fallback(string $bytes, string $name, string $message): void
    {
        $encoder = $this->encoder();
        $upload = ProfilePhotoFixtures::fromBytes($bytes, $name);
        try {
            $encoder->encode($upload);
            $this->fail('Invalid input must not produce stored original bytes.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->errors()['customer_photo'][0]);
        } finally {
            unlink($upload->getPathname());
        }
    }

    public function test_over_twenty_megapixels_is_rejected_before_decoding(): void
    {
        $encoder = $this->encoder();
        $upload = ProfilePhotoFixtures::portrait(20, 20, 'png');
        $bytes = file_get_contents($upload->getPathname());
        // IHDR dimensions are checked before any potentially large GD allocation.
        $bytes = substr_replace($bytes, pack('NN', 6000, 4000), 16, 8);
        $big = ProfilePhotoFixtures::fromBytes($bytes, 'large.png');
        try {
            $encoder->encode($big);
            $this->fail('24 MP must be refused before GD decode.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('20 megapixels', $e->errors()['customer_photo'][0]);
        } finally {
            unlink($upload->getPathname());
            unlink($big->getPathname());
        }
    }

    public function test_corrupt_image_that_has_valid_dimensions_still_fails_closed(): void
    {
        $encoder = $this->encoder();
        $upload = ProfilePhotoFixtures::portrait(20, 20, 'png');
        $bad = ProfilePhotoFixtures::fromBytes(substr(file_get_contents($upload->getPathname()), 0, 33), 'truncated.png');
        try {
            $encoder->encode($bad);
            $this->fail('A readable header is not proof of decodable image content.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_photo', $e->errors());
        } finally {
            unlink($upload->getPathname());
            unlink($bad->getPathname());
        }
    }

    public static function otherOrientations(): array
    {
        $cases = [];
        foreach (['png', 'webp'] as $format) {
            foreach (self::orientations() as [$orientation, $corners]) $cases[] = [$format, $orientation, $corners];
        }
        return $cases;
    }

    #[DataProvider('otherOrientations')]
    public function test_png_and_webp_exif_orientation_is_applied_before_metadata_is_stripped(string $format, int $orientation, array $corners): void
    {
        $input = ProfilePhotoFixtures::oriented($orientation, $format);
        $this->assertSame('image/'.$format, getimagesize($input->getPathname())['mime']);
        $result = $this->encoder()->encode($input);
        $image = imagecreatefromstring($result['bytes']);
        $this->assertSame($orientation >= 5 ? [80, 120] : [120, 80], [imagesx($image), imagesy($image)]);
        $colors = ['R' => [255, 0, 0], 'G' => [0, 255, 0], 'B' => [0, 0, 255], 'Y' => [255, 255, 0]];
        foreach ([[10, 10], [imagesx($image)-11, 10], [10, imagesy($image)-11], [imagesx($image)-11, imagesy($image)-11]] as $i => [$x, $y]) {
            $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            foreach (['red', 'green', 'blue'] as $c => $channel) $this->assertEqualsWithDelta($colors[$corners[$i]][$c], $rgb[$channel], 25);
        }
        $this->assertStringNotContainsString('EXIF', $result['bytes']);
        $this->assertStringNotContainsString('XMP ', $result['bytes']);
        imagedestroy($image);
    }

    /** @return array<string, array{string}> */
    public static function damagedJpegs(): array
    {
        $jpeg = ProfilePhotoFixtures::jpegBytes();
        $progressive = ProfilePhotoFixtures::jpegBytes(progressive: true);
        $sos = strpos($jpeg, "\xFF\xDA");

        return [
            // The preview found this one accepted and saved, the missing part grey.
            'cut to a third of its bytes' => [substr($jpeg, 0, intdiv(strlen($jpeg), 3))],
            'only the end-of-image marker missing' => [substr($jpeg, 0, -2)],
            'cut inside the scan header' => [substr($jpeg, 0, $sos + 6)],
            'cut right after the scan header' => [substr($jpeg, 0, $sos + 2 + unpack('n', substr($jpeg, $sos + 2, 2))[1])],
            'progressive cut between scans' => [substr($progressive, 0, strrpos($progressive, "\xFF\xDA"))],
            'progressive cut inside the last scan' => [substr($progressive, 0, -40)],
            // A complete thumbnail inside EXIF has its own end marker; the main image still lacks one.
            'main image cut, complete EXIF thumbnail' => [substr(ProfilePhotoFixtures::withExifThumbnail($jpeg), 0, -400)],
        ];
    }

    #[DataProvider('damagedJpegs')]
    public function test_incomplete_or_damaged_jpeg_is_rejected_with_a_useful_message(string $bytes): void
    {
        $upload = ProfilePhotoFixtures::fromBytes($bytes, 'damaged.jpg');
        try {
            $this->encoder()->encode($upload);
            $this->fail('A JPEG without its complete image data was accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('incomplete or damaged', $e->errors()['customer_photo'][0]);
        }
    }

    /** @return array<string, array{string}> */
    public static function completeJpegs(): array
    {
        $jpeg = ProfilePhotoFixtures::jpegBytes();

        return [
            'baseline' => [$jpeg],
            'progressive' => [ProfilePhotoFixtures::jpegBytes(progressive: true)],
            'motion photo with the video after the end marker' => [ProfilePhotoFixtures::motionPhoto()],
            'EXIF thumbnail' => [ProfilePhotoFixtures::withExifThumbnail($jpeg)],
            'zero padding after the end marker' => [$jpeg.str_repeat("\0", 512)],
            'fill bytes before a marker' => [substr($jpeg, 0, 2)."\xFF\xFF\xFF".substr($jpeg, 3)],
        ];
    }

    #[DataProvider('completeJpegs')]
    public function test_complete_jpegs_including_camera_trailers_are_still_accepted_and_stripped(string $bytes): void
    {
        $result = $this->encoder()->encode(ProfilePhotoFixtures::fromBytes($bytes, 'camera.jpg'));
        $info = getimagesizefromstring($result['bytes']);
        $this->assertSame(['image/webp', 512, 384], [$info['mime'], $info[0], $info[1]]);
        foreach (['Exif', 'EXIF', 'XMP', 'MotionPhoto', 'ftyp'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $result['bytes']);
        }
    }

    public function test_a_motion_photo_with_exif_orientation_is_still_rotated(): void
    {
        $oriented = file_get_contents(($file = ProfilePhotoFixtures::oriented(6))->getPathname());
        $result = $this->encoder()->encode(ProfilePhotoFixtures::fromBytes($oriented.pack('N', 24).'ftypmp42'.str_repeat("\xFF\xDA", 8), 'motion.jpg'));
        $image = imagecreatefromstring($result['bytes']);
        $this->assertSame([80, 120], [imagesx($image), imagesy($image)]);
        unlink($file->getPathname());
    }
}

<?php

namespace Tests\Feature;

use App\Services\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageOptimizerTest extends TestCase
{
    public function test_converts_uploaded_image_to_webp_and_caps_dimensions(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('big.jpg', 3000, 2000);

        $path = app(ImageOptimizer::class)->optimizeAndStore($file, 'items', 'public');

        $this->assertStringStartsWith('items/', $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);

        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertNotFalse($info);
        $this->assertSame('image/webp', $info['mime']);
        $this->assertLessThanOrEqual(ImageOptimizer::MAX_EDGE, max($info[0], $info[1]), 'longest edge must be capped');
    }

    public function test_passes_through_non_image_files_unchanged(): void
    {
        Storage::fake('public');
        $pdf = UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf');

        $path = app(ImageOptimizer::class)->optimizeAndStore($pdf, 'imports', 'public');

        $this->assertStringEndsWith('.pdf', $path);
        Storage::disk('public')->assertExists($path);
    }

    /** Synthetic GIF bytes (made with GD here; nothing malformed, no exploit payload). */
    private static function gifBytes(): string
    {
        $gd = imagecreatetruecolor(40, 30);
        ob_start();
        imagegif($gd);
        imagedestroy($gd);

        return (string) ob_get_clean();
    }

    /**
     * CVE-2026-9672 (libgd GIF decoder) is unpatched in the server's libgd, so a
     * GIF is never handed to GD here: it is stored exactly as uploaded, and the
     * browser decodes it. Before this, item/product/logo/repair GIFs were decoded.
     */
    public function test_an_uploaded_gif_is_stored_as_uploaded_and_never_decoded(): void
    {
        Storage::fake('public');
        $tmp = tempnam(sys_get_temp_dir(), 'gif');
        file_put_contents($tmp, self::gifBytes());
        $file = new UploadedFile($tmp, 'ring.gif', 'image/gif', null, true);

        $path = app(ImageOptimizer::class)->optimizeAndStore($file, 'items', 'public');

        $this->assertStringEndsWith('.gif', $path);
        $this->assertSame(self::gifBytes(), Storage::disk('public')->get($path));
    }

    public function test_gif_bytes_on_the_binary_path_are_stored_as_sent(): void
    {
        Storage::fake('public');

        // The mobile repair path passes whatever bytes arrived; the caller's extension is kept.
        $path = app(ImageOptimizer::class)->optimizeAndStoreBinary(self::gifBytes(), 'repairs/1', 'public', 'jpg');

        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame(self::gifBytes(), Storage::disk('public')->get($path));
    }

    public function test_a_gif_claiming_another_type_is_still_not_decoded(): void
    {
        Storage::fake('public');
        $tmp = tempnam(sys_get_temp_dir(), 'gif');
        file_put_contents($tmp, self::gifBytes());
        // The client says JPEG; the bytes say GIF. The bytes decide.
        $file = new UploadedFile($tmp, 'ring.jpg', 'image/jpeg', null, true);

        $path = app(ImageOptimizer::class)->optimizeAndStore($file, 'items', 'public');

        $this->assertStringEndsWith('.gif', $path, 'stored under the extension of its real content, as before for non-images');
        $this->assertSame(self::gifBytes(), Storage::disk('public')->get($path));
    }

    public function test_jpeg_png_and_webp_are_still_converted(): void
    {
        Storage::fake('public');
        foreach (['a.jpg', 'a.png', 'a.webp'] as $name) {
            $path = app(ImageOptimizer::class)->optimizeAndStore(UploadedFile::fake()->image($name, 300, 200), 'items', 'public');
            $this->assertStringEndsWith('.webp', $path, $name);
        }
    }

    public function test_optimizes_raw_binary_to_webp(): void
    {
        Storage::fake('public');
        $gd = imagecreatetruecolor(2400, 1200);
        ob_start();
        imagepng($gd);
        $binary = (string) ob_get_clean();
        imagedestroy($gd);

        $path = app(ImageOptimizer::class)->optimizeAndStoreBinary($binary, 'repairs/1', 'public', 'png');

        $this->assertStringStartsWith('repairs/1/', $path);
        $this->assertStringEndsWith('.webp', $path);
        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame('image/webp', $info['mime']);
        $this->assertLessThanOrEqual(ImageOptimizer::MAX_EDGE, max($info[0], $info[1]));
    }
}

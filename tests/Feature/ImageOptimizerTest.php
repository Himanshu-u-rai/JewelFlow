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

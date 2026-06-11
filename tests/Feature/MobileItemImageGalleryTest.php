<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Mobile item-show (read side) must expose the full image gallery additively:
 *  - `image`  stays the single primary resolved URL (backward compatible).
 *  - `images` is the whole gallery as resolved absolute URLs, primary first,
 *    de-duped; a single-image item yields a one-element array.
 *
 * Items are created via the mobile store (proven path) and the gallery is
 * attached directly — multi-image upload is web-only; this is a read-side test.
 */
class MobileItemImageGalleryTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // This is a response-shape test, not an authz test. Bypass CSRF and the
        // can:* permission gate (the test harness doesn't seed role permissions —
        // the same gap that reds MobileRetailerItemPricingParityTest).
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    }

    public function test_mobile_item_show_exposes_full_gallery_primary_first_with_absolute_urls(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Sanctum::actingAs($user);

        $barcode = 'RTL-MOB-GAL-003';
        $itemId = $this->createMobileItem($barcode, $shop->id);

        // Attach a 3-image gallery (primary + two more).
        DB::table('items')->where('id', $itemId)->update([
            'image'  => 'items/primary.jpg',
            'images' => json_encode(['items/second.jpg', 'items/third.jpg']),
        ]);

        $item = Item::withoutTenant()->findOrFail($itemId);
        $this->assertCount(3, $item->image_gallery); // sanity: gallery merged primary-first

        $response = $this->getJson('/api/mobile/items/barcode/' . $barcode);
        $response->assertOk();

        $image  = $response->json('image');
        $images = $response->json('images');

        // `image` unchanged: present + absolute.
        $this->assertIsString($image);
        $this->assertStringStartsWith('http', $image);

        // `images`: array of absolute URLs, primary first, 3 entries, de-duped.
        $this->assertIsArray($images);
        $this->assertCount(3, $images);
        $this->assertSame($image, $images[0], 'primary image must be first');
        foreach ($images as $url) {
            $this->assertIsString($url);
            $this->assertStringStartsWith('http', $url, 'gallery URLs must be absolute');
        }
        $this->assertSame(array_values(array_unique($images)), $images, 'gallery must be de-duped');
    }

    public function test_mobile_item_show_single_image_returns_single_element_array(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Sanctum::actingAs($user);

        $barcode = 'RTL-MOB-GAL-001';
        $itemId = $this->createMobileItem($barcode, $shop->id);

        DB::table('items')->where('id', $itemId)->update([
            'image'  => 'items/only.jpg',
            'images' => null,
        ]);

        $response = $this->getJson('/api/mobile/items/barcode/' . $barcode);
        $response->assertOk();

        $image  = $response->json('image');
        $images = $response->json('images');

        $this->assertIsString($image);
        $this->assertIsArray($images);
        $this->assertCount(1, $images);
        $this->assertSame($image, $images[0]);
    }

    /** Create an item via the mobile store and return its id. */
    private function createMobileItem(string $barcode, int $shopId): int
    {
        $this->postJson('/api/mobile/items', [
            'barcode' => $barcode,
            'design' => 'Gallery Test Item',
            'category' => 'Gold Jewellery',
            'sub_category' => 'Rings',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'stone_weight' => 1,
            'purity' => 22,
            'making_charges' => 500,
            'stone_charges' => 200,
            'hallmark_charges' => 50,
            'rhodium_charges' => 25,
            'other_charges' => 10,
            'cost_price' => 1,
            'selling_price' => 999999,
        ])->assertCreated();

        return (int) Item::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('barcode', $barcode)
            ->value('id');
    }
}

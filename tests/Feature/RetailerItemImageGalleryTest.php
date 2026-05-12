<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Support\TenantContext;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class RetailerItemImageGalleryTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_retailer_item_create_with_one_image_keeps_primary_image_and_gallery_aligned(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Storage::fake('public');

        $response = $this->actingAs($user)->post(route('inventory.items.store'), array_merge(
            $this->validRetailerPayload('RTL-GAL-001'),
            ['images' => [UploadedFile::fake()->image('front.jpg', 300, 300)->size(512)]]
        ));

        $response->assertRedirect(route('inventory.items.index'));

        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'RTL-GAL-001')->firstOrFail();

        $this->assertIsArray($item->images);
        $this->assertCount(1, $item->images);
        $this->assertSame($item->image, $item->images[0]);
        $this->assertSame([$item->image], $item->image_gallery);
        Storage::disk('public')->assertExists($item->image);
    }

    public function test_retailer_item_create_with_four_images_stores_gallery_and_first_as_primary(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Storage::fake('public');

        $files = [
            UploadedFile::fake()->image('front.jpg', 300, 300)->size(512),
            UploadedFile::fake()->image('side.png', 300, 300)->size(512),
            UploadedFile::fake()->image('back.webp', 300, 300)->size(512),
            UploadedFile::fake()->image('detail.bmp', 300, 300)->size(512),
        ];

        $response = $this->actingAs($user)->post(route('inventory.items.store'), array_merge(
            $this->validRetailerPayload('RTL-GAL-004'),
            ['images' => $files]
        ));

        $response->assertRedirect(route('inventory.items.index'));

        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'RTL-GAL-004')->firstOrFail();

        $this->assertCount(4, $item->images);
        $this->assertSame($item->images[0], $item->image);
        $this->assertSame($item->images, $item->image_gallery);

        foreach ($item->images as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_retailer_item_create_rejects_more_than_four_images(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Storage::fake('public');

        $response = $this->actingAs($user)
            ->from(route('inventory.items.create'))
            ->post(route('inventory.items.store'), array_merge(
                $this->validRetailerPayload('RTL-GAL-005'),
                ['images' => [
                    UploadedFile::fake()->image('one.jpg')->size(512),
                    UploadedFile::fake()->image('two.jpg')->size(512),
                    UploadedFile::fake()->image('three.jpg')->size(512),
                    UploadedFile::fake()->image('four.jpg')->size(512),
                    UploadedFile::fake()->image('five.jpg')->size(512),
                ]]
            ));

        $response->assertRedirect(route('inventory.items.create'));
        $response->assertSessionHasErrors('images');
        $this->assertFalse(Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'RTL-GAL-005')->exists());
    }

    public function test_retailer_item_edit_removes_selected_images_appends_new_images_and_updates_primary(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Storage::fake('public');

        Storage::disk('public')->put('items/old-front.jpg', 'front');
        Storage::disk('public')->put('items/old-side.jpg', 'side');

        $item = $this->createItem($shop->id, null, [
            'barcode' => 'RTL-GAL-EDIT',
            'source' => 'purchased',
            'image' => 'items/old-front.jpg',
            'images' => ['items/old-front.jpg', 'items/old-side.jpg'],
        ]);

        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->put(route('inventory.items.update', $item), array_merge(
            $this->validRetailerPayload('RTL-GAL-EDIT'),
            [
                'remove_images' => ['items/old-front.jpg'],
                'images' => [UploadedFile::fake()->image('new-detail.jpg', 300, 300)->size(512)],
            ]
        )));

        $response->assertRedirect(route('inventory.items.show', $item));

        $item->refresh();

        $this->assertCount(2, $item->images);
        $this->assertSame('items/old-side.jpg', $item->images[0]);
        $this->assertSame('items/old-side.jpg', $item->image);
        $this->assertNotContains('items/old-front.jpg', $item->images);
        $this->assertStringStartsWith('items/', $item->images[1]);
        Storage::disk('public')->assertMissing('items/old-front.jpg');
        Storage::disk('public')->assertExists('items/old-side.jpg');
        Storage::disk('public')->assertExists($item->images[1]);
    }

    public function test_retailer_item_create_rejects_oversized_gallery_image(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Storage::fake('public');

        $response = $this->actingAs($user)
            ->from(route('inventory.items.create'))
            ->post(route('inventory.items.store'), array_merge(
                $this->validRetailerPayload('RTL-GAL-BIG'),
                ['images' => [UploadedFile::fake()->image('too-large.jpg')->size(5121)]]
            ));

        $response->assertRedirect(route('inventory.items.create'));
        $response->assertSessionHasErrors('images.0');
        $this->assertFalse(Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'RTL-GAL-BIG')->exists());
    }

    public function test_manufacturer_item_create_still_accepts_single_image_field(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        Storage::fake('public');

        $response = $this->actingAs($user)->post(route('inventory.items.store'), [
            'barcode' => 'MFG-IMG-001',
            'design' => 'Manufactured Ring',
            'category' => 'Ring',
            'sub_category' => 'Daily Wear',
            'metal_lot_id' => $lot->id,
            'gross_weight' => 10,
            'stone_weight' => 1,
            'purity' => 22,
            'making_charges' => 500,
            'stone_charges' => 200,
            'image' => UploadedFile::fake()->image('manufacturer.jpg')->size(512),
        ]);

        $response->assertRedirect(route('inventory.items.index'));

        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'MFG-IMG-001')->firstOrFail();
        $this->assertNotNull($item->image);
        $this->assertNull($item->images);
        Storage::disk('public')->assertExists($item->image);
    }

    public function test_manufacturer_item_edit_still_accepts_single_image_field(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        Storage::fake('public');

        $item = $this->createItem($shop->id, $lot->id, [
            'barcode' => 'MFG-IMG-EDIT',
            'image' => null,
            'images' => null,
        ]);

        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->put(route('inventory.items.update', $item), [
            'barcode' => 'MFG-IMG-EDIT',
            'design' => 'Updated Manufactured Ring',
            'category' => 'Ring',
            'sub_category' => 'Daily Wear',
            'making_charges' => 600,
            'stone_charges' => 250,
            'image' => UploadedFile::fake()->image('manufacturer-edit.jpg')->size(512),
        ]));

        $response->assertRedirect(route('inventory.items.show', $item));

        $item->refresh();
        $this->assertNotNull($item->image);
        $this->assertNull($item->images);
        Storage::disk('public')->assertExists($item->image);
    }

    private function validRetailerPayload(string $barcode): array
    {
        return [
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
        ];
    }
}

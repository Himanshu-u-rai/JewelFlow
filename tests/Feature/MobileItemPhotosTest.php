<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Photos on a new item from the app, as on the web form: up to 4; the first is
 * the main photo (`image`), all of them make the carousel (`images`).
 * Also: the app is told whether "% of metal / ₹ per gram" making is switched on.
 */
class MobileItemPhotosTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake('public');
    }

    /** A real 1x1 PNG, base64 (the server checks the bytes, not the name). */
    private function png(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    private function retailer(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);
        $this->seedRetailerPricing($shop, $user);
        TenantContext::runFor($shop->id, fn () => \App\Models\Category::create(['shop_id' => $shop->id, 'name' => 'Ring']));
    }

    private function newItem(array $over = [])
    {
        return $this->postJson('/api/mobile/items', array_merge([
            'barcode' => 'PHOTO-1', 'category' => 'Ring', 'metal_type' => 'gold', 'purity' => 22,
            'gross_weight' => 5, 'stone_weight' => 0, 'cost_price' => 0, 'selling_price' => 0,
        ], $over));
    }

    public function test_several_photos_first_is_main_all_in_the_carousel(): void
    {
        $this->retailer();

        $this->newItem(['images_base64' => [$this->png(), $this->png(), $this->png()]])->assertCreated();

        $item = Item::withoutTenant()->where('barcode', 'PHOTO-1')->firstOrFail();
        $this->assertCount(3, $item->images);
        $this->assertSame($item->images[0], $item->image);
        foreach ($item->images as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_more_than_four_photos_is_refused_like_the_web(): void
    {
        $this->retailer();

        $this->newItem(['images_base64' => array_fill(0, 5, $this->png())])
            ->assertStatus(422)->assertJsonValidationErrors(['images_base64']);
        $this->assertSame(0, Item::withoutTenant()->count());
    }

    public function test_a_photo_that_is_not_an_image_is_refused(): void
    {
        $this->retailer();

        $this->newItem(['images_base64' => [base64_encode('not a picture')]])->assertStatus(422);
        $this->assertSame(0, Item::withoutTenant()->count());
    }

    private function editItem(Item $item, array $payload)
    {
        // PHPUnit has no authed-request tenant for the {item} binding (real HTTP does).
        TenantContext::set((int) $item->shop_id);

        return $this->patchJson("/api/mobile/items/{$item->id}", $payload);
    }

    public function test_editing_photos_like_the_web_remove_some_add_new_first_left_is_main(): void
    {
        $this->retailer();
        $this->newItem(['images_base64' => [$this->png(), $this->png()]])->assertCreated();
        $item = Item::withoutTenant()->where('barcode', 'PHOTO-1')->firstOrFail();
        [$first, $second] = $item->images;

        // The app knows its photos by URL; it asks to remove the main one and adds one.
        $firstUrl = 'http://localhost/storage/' . $first;
        $this->editItem($item, ['remove_images' => [$firstUrl], 'images_base64' => [$this->png()]])->assertOk();

        $item->refresh();
        $this->assertCount(2, $item->images);
        $this->assertSame($second, $item->image, 'the first photo left becomes the main one');
        $this->assertSame($second, $item->images[0]);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($item->images[1]);
    }

    public function test_older_apps_replacing_the_main_photo_no_longer_leave_a_broken_photo_in_the_carousel(): void
    {
        $this->retailer();
        $this->newItem(['images_base64' => [$this->png(), $this->png()]])->assertCreated();
        $item = Item::withoutTenant()->where('barcode', 'PHOTO-1')->firstOrFail();
        [$oldMain, $second] = $item->images;

        $this->editItem($item, ['image_base64' => $this->png()])->assertOk();

        $item->refresh();
        $this->assertNotContains($oldMain, $item->images, 'the deleted file is not left in the carousel');
        $this->assertSame([$item->image, $second], $item->images);
        Storage::disk('public')->assertMissing($oldMain);
    }

    public function test_editing_cannot_go_past_four_photos(): void
    {
        $this->retailer();
        $this->newItem(['images_base64' => array_fill(0, 3, $this->png())])->assertCreated();
        $item = Item::withoutTenant()->where('barcode', 'PHOTO-1')->firstOrFail();

        $this->editItem($item, ['images_base64' => [$this->png(), $this->png()]])
            ->assertStatus(422)->assertJsonValidationErrors(['images_base64']);
        $this->assertCount(3, $item->fresh()->images);
    }

    public function test_editing_an_item_keeps_its_making_mode_as_the_web_edit_does(): void
    {
        config(['features.making_charge_modes' => true]);
        $this->retailer();
        $this->newItem(['making_charge_type' => 'percentage', 'making_charge_value' => 10])->assertCreated();
        $item = Item::withoutTenant()->where('barcode', 'PHOTO-1')->firstOrFail();
        $this->assertSame('percentage', $item->making_charge_type);

        $this->editItem($item, ['design' => 'Renamed ring'])->assertOk();

        $item->refresh();
        $this->assertSame('percentage', $item->making_charge_type);
        $this->assertEquals(10, (float) $item->making_charge_value);
    }

    public function test_every_user_learns_at_start_up_whether_making_modes_are_on(): void
    {
        // Quick bill staff may not see stock, so the switch also comes with the app's start-up data.
        $this->retailer();

        $this->getJson('/api/mobile/bootstrap')->assertOk()->assertJsonPath('config.making_charge_modes', false);

        config(['features.making_charge_modes' => true]);
        $this->getJson('/api/mobile/bootstrap')->assertOk()->assertJsonPath('config.making_charge_modes', true);
    }

    public function test_the_app_is_told_whether_making_modes_are_on(): void
    {
        $this->retailer();

        $this->getJson('/api/mobile/items/retailer-pricing-meta')->assertOk()->assertJson(['making_charge_modes' => false]);

        config(['features.making_charge_modes' => true]);
        $this->getJson('/api/mobile/items/retailer-pricing-meta')->assertOk()->assertJson(['making_charge_modes' => true]);
    }
}

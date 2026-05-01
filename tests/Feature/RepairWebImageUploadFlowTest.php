<?php

namespace Tests\Feature;

use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class RepairWebImageUploadFlowTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_web_repair_create_flow_uploads_photo_and_shows_it_on_detail_page(): void
    {
        Storage::fake('public');
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);

        $response = $this->actingAs($user)->post(route('repairs.store'), [
            'customer_id' => $customer->id,
            'item_description' => 'Broken chain',
            'description' => 'Fix lock',
            'gross_weight' => 8.540,
            'purity' => 22,
            'estimated_cost' => 450,
            'image' => UploadedFile::fake()->image('repair-photo.png', 128, 128),
        ]);

        $response->assertRedirect(route('repairs.index'));
        $response->assertSessionHas('success', 'Repair item received successfully!');

        $repair = Repair::withoutTenant()
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull($repair->image_path);
        $this->assertSame($repair->image_path, $repair->image);
        Storage::disk('public')->assertExists($repair->image_path);

        $detail = TenantContext::runFor($shop->id, function () use ($user, $repair) {
            return $this->actingAs($user)->get(route('repairs.show', $repair));
        });
        $detail->assertOk();
        $detail->assertSee('Item Photo');
        $detail->assertSee($repair->image_path);
    }
}

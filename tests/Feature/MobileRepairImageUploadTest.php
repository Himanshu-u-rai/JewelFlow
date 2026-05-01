<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class MobileRepairImageUploadTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const JPEG_BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////2wBDAf//////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAXAQEBAQEAAAAAAAAAAAAAAAABAgAD/9oADAMBAAIQAxAAAAH0j//EABQQAQAAAAAAAAAAAAAAAAAAACD/2gAIAQEAAQUCcf/EABQRAQAAAAAAAAAAAAAAAAAAACD/2gAIAQMBAT8BJ//EABQRAQAAAAAAAAAAAAAAAAAAACD/2gAIAQIBAT8BJ//EABQQAQAAAAAAAAAAAAAAAAAAACD/2gAIAQEABj8Cf//Z';
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+nx7kAAAAASUVORK5CYII=';
    private const WEBP_BASE64 = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/vuUAAA=';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_mobile_repair_store_accepts_valid_jpeg_png_and_webp_base64_and_persists_image_path(): void
    {
        Storage::fake('public');
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        $samples = [
            'jpg' => self::JPEG_BASE64,
            'png' => self::PNG_BASE64,
            'webp' => self::WEBP_BASE64,
        ];

        $lastRepairId = null;
        $lastPath = null;

        foreach ($samples as $expectedExt => $base64) {
            $response = $this->postJson('/api/mobile/repairs', $this->repairPayload($customer->id, [
                'image_base64' => $base64,
            ]));

            $response->assertCreated();
            $path = (string) $response->json('image_path');
            $repairId = (int) $response->json('id');

            $this->assertNotSame('', $path);
            $this->assertStringStartsWith("repairs/{$shop->id}/", $path);
            $this->assertTrue(Str::endsWith($path, '.' . $expectedExt));
            $this->assertSame($path, $response->json('image'));
            $this->assertSame(asset('storage/' . $path), $response->json('image_url'));

            Storage::disk('public')->assertExists($path);
            $this->assertDatabaseHas('repairs', [
                'id' => $repairId,
                'shop_id' => $shop->id,
                'image' => $path,
                'image_path' => $path,
            ]);

            $lastRepairId = $repairId;
            $lastPath = $path;
        }

        $this->assertNotNull($lastRepairId);
        $this->assertNotNull($lastPath);

        $listResponse = $this->getJson('/api/mobile/repairs');
        $listResponse->assertOk();

        $listedRepair = collect($listResponse->json('data'))->firstWhere('id', $lastRepairId);
        $this->assertNotNull($listedRepair);
        $this->assertSame($lastPath, data_get($listedRepair, 'image_path'));
        $this->assertSame(asset('storage/' . $lastPath), data_get($listedRepair, 'image_url'));

        $detailResponse = $this->getJson("/api/mobile/repairs/{$lastRepairId}");
        $detailResponse->assertOk();
        $detailResponse->assertJsonPath('id', $lastRepairId);
        $detailResponse->assertJsonPath('image_path', $lastPath);
        $detailResponse->assertJsonPath('image_url', asset('storage/' . $lastPath));
    }

    public function test_mobile_repair_store_rejects_invalid_base64(): void
    {
        Storage::fake('public');
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/repairs', $this->repairPayload($customer->id, [
            'image_base64' => '%%%INVALID-BASE64%%%',
            'item_description' => 'Invalid base64 repair test',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image_base64']);
        $this->assertDatabaseMissing('repairs', [
            'shop_id' => $shop->id,
            'item_description' => 'Invalid base64 repair test',
        ]);
    }

    public function test_mobile_repair_store_rejects_invalid_image_mime(): void
    {
        Storage::fake('public');
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/repairs', $this->repairPayload($customer->id, [
            'image_base64' => base64_encode('this is not an image'),
            'item_description' => 'Invalid mime repair test',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image_base64']);
        $this->assertDatabaseMissing('repairs', [
            'shop_id' => $shop->id,
            'item_description' => 'Invalid mime repair test',
        ]);
    }

    public function test_mobile_repair_store_rejects_oversized_image_payload(): void
    {
        Storage::fake('public');
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        $basePng = base64_decode(self::PNG_BASE64, true);
        $this->assertNotFalse($basePng);

        $oversizedBytes = $basePng . str_repeat('A', (5 * 1024 * 1024) + 1);
        $response = $this->postJson('/api/mobile/repairs', $this->repairPayload($customer->id, [
            'image_base64' => base64_encode($oversizedBytes),
            'item_description' => 'Oversized image repair test',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image_base64']);
        $this->assertDatabaseMissing('repairs', [
            'shop_id' => $shop->id,
            'item_description' => 'Oversized image repair test',
        ]);
    }

    public function test_mobile_repair_store_without_image_keeps_image_fields_null(): void
    {
        Storage::fake('public');
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/repairs', $this->repairPayload($customer->id));
        $response->assertCreated();
        $repairId = (int) $response->json('id');

        $response->assertJsonPath('image', null);
        $response->assertJsonPath('image_path', null);
        $response->assertJsonPath('image_url', null);

        $this->assertDatabaseHas('repairs', [
            'id' => $repairId,
            'shop_id' => $shop->id,
            'image' => null,
            'image_path' => null,
        ]);
    }

    private function repairPayload(int $customerId, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customerId,
            'item_description' => 'Broken clasp repair',
            'description' => 'Fix clasp and polish',
            'due_date' => now()->addDays(3)->toDateString(),
            'gross_weight' => 6.250,
            'purity' => 22,
            'estimated_cost' => 350.00,
        ], $overrides);
    }
}

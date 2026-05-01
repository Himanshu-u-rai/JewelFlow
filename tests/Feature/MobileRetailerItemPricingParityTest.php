<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Karigar;
use App\Models\Vendor;
use App\Services\ShopPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class MobileRetailerItemPricingParityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_mobile_retailer_item_store_persists_charge_breakdown_and_barcode_payload_fields(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/items', [
            'barcode' => 'RTL-MOB-PARITY-001',
            'design' => 'Retailer Gold Ring',
            'category' => 'Gold Jewellery',
            'sub_category' => 'Rings',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'stone_weight' => 1,
            'purity' => 22,
            'making_charges' => 500,
            'stone_charges' => 200,
            'hallmark_charges' => 25,
            'rhodium_charges' => 15,
            'other_charges' => 10,
            'cost_price' => 1,
            'selling_price' => 999999,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('metal_type', 'gold');
        $response->assertJsonPath('purity_label', '22K');
        $response->assertJsonPath('hallmark_charges', 25.0);
        $response->assertJsonPath('rhodium_charges', 15.0);
        $response->assertJsonPath('other_charges', 10.0);
        $response->assertJsonPath('resolved_rate_per_gram', 6600.0);
        $this->assertSame(59400.0, round((float) $response->json('cost_price'), 2));
        $this->assertSame(60150.0, round((float) $response->json('selling_price'), 2));

        $item = Item::withoutTenant()
            ->where('shop_id', $shop->id)
            ->where('barcode', 'RTL-MOB-PARITY-001')
            ->firstOrFail();

        $this->assertSame(25.0, round((float) $item->hallmark_charges, 2));
        $this->assertSame(15.0, round((float) $item->rhodium_charges, 2));
        $this->assertSame(10.0, round((float) $item->other_charges, 2));
        $this->assertSame(59400.0, round((float) $item->cost_price, 2));
        $this->assertSame(60150.0, round((float) $item->selling_price, 2));

        $barcodeResponse = $this->getJson('/api/mobile/items/barcode/RTL-MOB-PARITY-001');

        $barcodeResponse->assertOk();
        $barcodeResponse->assertJsonPath('metal_type', 'gold');
        $barcodeResponse->assertJsonPath('purity_label', '22K');
        $barcodeResponse->assertJsonPath('hallmark_charges', 25.0);
        $barcodeResponse->assertJsonPath('rhodium_charges', 15.0);
        $barcodeResponse->assertJsonPath('other_charges', 10.0);
        $barcodeResponse->assertJsonPath('resolved_rate_per_gram', 6600.0);
    }

    public function test_mobile_retailer_item_update_recomputes_selling_price_with_existing_charge_fallbacks(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Sanctum::actingAs($user);

        $item = $this->createItem($shop->id, null, [
            'barcode' => 'RTL-MOB-UPD-001',
            'design' => 'Retailer Updated Ring',
            'category' => 'Gold Jewellery',
            'sub_category' => 'Rings',
            'gross_weight' => 10,
            'stone_weight' => 1,
            'net_metal_weight' => 9,
            'purity' => 22,
            'making_charges' => 100,
            'stone_charges' => 50,
            'hallmark_charges' => 10,
            'rhodium_charges' => 20,
            'other_charges' => 30,
            'metal_type' => 'gold',
            'cost_price' => 59400,
            'selling_price' => 59610,
            'source' => 'purchase',
            'status' => 'in_stock',
            'pricing_review_required' => false,
            'pricing_review_notes' => null,
        ]);

        $response = $this->putJson("/api/mobile/items/{$item->id}", [
            'hallmark_charges' => 40,
            'selling_price' => 999999,
        ]);

        $response->assertOk();
        $response->assertJsonPath('hallmark_charges', 40.0);
        $response->assertJsonPath('rhodium_charges', 20.0);
        $response->assertJsonPath('other_charges', 30.0);
        $response->assertJsonPath('resolved_rate_per_gram', 6600.0);
        $this->assertSame(59400.0, round((float) $response->json('cost_price'), 2));
        $this->assertSame(59640.0, round((float) $response->json('selling_price'), 2));

        $fresh = Item::withoutTenant()->findOrFail($item->id);

        $this->assertSame(40.0, round((float) $fresh->hallmark_charges, 2));
        $this->assertSame(20.0, round((float) $fresh->rhodium_charges, 2));
        $this->assertSame(30.0, round((float) $fresh->other_charges, 2));
        $this->assertSame(59400.0, round((float) $fresh->cost_price, 2));
        $this->assertSame(59640.0, round((float) $fresh->selling_price, 2));
    }

    public function test_mobile_retailer_item_patch_recomputes_selling_price_with_charge_updates(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Sanctum::actingAs($user);

        $item = $this->createItem($shop->id, null, [
            'barcode' => 'RTL-MOB-PATCH-001',
            'design' => 'Retailer Patch Ring',
            'category' => 'Gold Jewellery',
            'sub_category' => 'Rings',
            'gross_weight' => 10,
            'stone_weight' => 1,
            'net_metal_weight' => 9,
            'purity' => 22,
            'making_charges' => 100,
            'stone_charges' => 50,
            'hallmark_charges' => 10,
            'rhodium_charges' => 20,
            'other_charges' => 30,
            'metal_type' => 'gold',
            'cost_price' => 59400,
            'selling_price' => 59610,
            'source' => 'purchase',
            'status' => 'in_stock',
            'pricing_review_required' => false,
            'pricing_review_notes' => null,
        ]);

        $response = $this->patchJson("/api/mobile/items/{$item->id}", [
            'other_charges' => 60,
            'selling_price' => 999999,
        ]);

        $response->assertOk();
        $response->assertJsonPath('hallmark_charges', 10.0);
        $response->assertJsonPath('rhodium_charges', 20.0);
        $response->assertJsonPath('other_charges', 60.0);
        $response->assertJsonPath('resolved_rate_per_gram', 6600.0);
        $this->assertSame(59400.0, round((float) $response->json('cost_price'), 2));
        $this->assertSame(59640.0, round((float) $response->json('selling_price'), 2));

        $fresh = Item::withoutTenant()->findOrFail($item->id);
        $this->assertSame(60.0, round((float) $fresh->other_charges, 2));
        $this->assertSame(59400.0, round((float) $fresh->cost_price, 2));
        $this->assertSame(59640.0, round((float) $fresh->selling_price, 2));
    }

    public function test_mobile_retailer_pricing_meta_endpoint_returns_profiles_and_resolved_rates(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Sanctum::actingAs($user);

        /** @var ShopPricingService $pricing */
        $pricing = app(ShopPricingService::class);

        $response = $this->getJson('/api/mobile/items/retailer-pricing-meta');

        $response->assertOk();
        $response->assertJsonPath('business_date', $pricing->businessDateString($shop));
        $response->assertJsonPath('purity_profiles.gold.0.value', '24');
        $response->assertJsonPath('purity_profiles.gold.0.label', '24K');
        $response->assertJsonPath('purity_profiles.silver.1.value', '925');
        $response->assertJsonPath('purity_profiles.silver.1.label', '925');
        $response->assertJsonPath('resolved_rates.gold.22.label', '22K');
        $response->assertJsonPath('resolved_rates.gold.22.rate_per_gram', 6600.0);
        $response->assertJsonPath('resolved_rates.silver.925.label', '925');
        $response->assertJsonPath('resolved_rates.silver.925.rate_per_gram', 85.1);
    }

    public function test_mobile_retailer_pricing_meta_endpoint_returns_409_when_rates_are_missing(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/items/retailer-pricing-meta');

        $response->assertStatus(409);
        $this->assertStringContainsString(
            "Today's retailer metal rates are missing",
            (string) $response->json('message')
        );
    }

    public function test_mobile_item_store_rejects_payload_when_vendor_and_karigar_are_both_set(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        Sanctum::actingAs($user);

        $vendor = new Vendor();
        $vendor->forceFill([
            'shop_id' => $shop->id,
            'name' => 'Vendor A',
            'is_active' => true,
        ]);
        $vendor->save();

        $karigar = Karigar::create([
            'shop_id' => $shop->id,
            'name' => 'Karigar A',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/mobile/items', [
            'barcode' => 'RTL-MOB-VK-CONFLICT-001',
            'design' => 'Conflict Ring',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 5,
            'stone_weight' => 0,
            'purity' => 22,
            'making_charges' => 200,
            'stone_charges' => 0,
            'vendor_id' => $vendor->id,
            'karigar_id' => $karigar->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['vendor_id', 'karigar_id']);

        $this->assertDatabaseMissing('items', [
            'shop_id' => $shop->id,
            'barcode' => 'RTL-MOB-VK-CONFLICT-001',
        ]);
    }
}

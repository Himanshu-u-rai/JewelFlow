<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Item;
use App\Models\MetalRate;
use App\Models\ShopDailyMetalRate;
use App\Services\BulkImportService;
use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class RetailerPricingTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipIfNotPostgres();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_owner_can_save_daily_rates_and_resolve_shop_purity_rates(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($user)->post(route('settings.pricing.save-rates'), [
            'gold_24k_rate_per_gram' => 7200,
            'silver_999_rate_per_kg' => 92000,
        ]);

        $response->assertRedirect(route('settings.edit', ['tab' => 'pricing']));

        $dailyRate = ShopDailyMetalRate::withoutTenant()
            ->where('shop_id', $shop->id)
            ->firstOrFail();

        $this->assertSame(7200.0, (float) $dailyRate->gold_24k_rate_per_gram);
        $this->assertSame(92.0, (float) $dailyRate->silver_999_rate_per_gram);

        $businessDate = $dailyRate->business_date->toDateString();
        $gold22 = MetalRate::latestResolvedForDay($shop->id, $businessDate, 'gold', 22.0);
        $silver925 = MetalRate::latestResolvedForDay($shop->id, $businessDate, 'silver', 925.0);

        $this->assertNotNull($gold22);
        $this->assertNotNull($silver925);
        $this->assertSame(6600.0, round((float) $gold22->rate_per_gram, 4));
        $this->assertSame(85.1, round((float) $silver925->rate_per_gram, 4));
    }

    public function test_web_retailer_item_store_computes_cost_price_from_daily_rates(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);

        $response = $this->actingAs($user)->post(route('inventory.items.store'), [
            'barcode' => 'RTL-WEB-001',
            'design' => 'Web Gold Ring',
            'category' => 'Gold Jewellery',
            'sub_category' => 'Rings',
            'metal_type' => 'gold',
            'gross_weight' => 10,
            'stone_weight' => 1,
            'purity' => 22,
            'making_charges' => 500,
            'stone_charges' => 200,
            'cost_price' => 1,
            'selling_price' => 70000,
        ]);

        $response->assertRedirect(route('inventory.items.index'));

        $item = Item::withoutTenant()
            ->where('shop_id', $shop->id)
            ->where('barcode', 'RTL-WEB-001')
            ->firstOrFail();

        $this->assertSame('gold', $item->metal_type);
        $this->assertSame(9.0, round((float) $item->net_metal_weight, 3));
        $this->assertSame(60100.0, round((float) $item->cost_price, 2));
        $this->assertFalse((bool) $item->pricing_review_required);
    }

    public function test_pricing_settings_displays_history_table_with_filters(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);

        /** @var ShopPricingService $pricing */
        $pricing = app(ShopPricingService::class);
        $profile = $pricing->profileForPurity($shop, 'gold', 22);

        $this->assertNotNull($profile);

        $this->actingAs($user)->post(route('settings.pricing.overrides.store', $profile), [
            'rate_per_gram' => 6543.21,
        ])->assertRedirect(route('settings.edit', ['tab' => 'pricing']));

        $response = $this->actingAs($user)->get(route('settings.edit', [
            'tab' => 'pricing',
            'history_metal_type' => 'gold',
            'history_purity_value' => '22',
            'history_entry_type' => 'override',
            'history_sort_by' => 'rate_per_gram',
            'history_sort_dir' => 'desc',
        ]));

        $response->assertOk();
        $response->assertSee('Price History');
        $response->assertSee('6543.2100');
        $response->assertSee('option value="override" selected', false);
        $response->assertSee('history_sort_by=business_date', false);
        $response->assertSee('history_sort_by=rate_per_gram', false);
    }

    public function test_mobile_retailer_item_store_computes_silver_cost_price_server_side(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user, [
            'silver_999_rate_per_gram' => 100,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/items', [
            'barcode' => 'RTL-MOB-001',
            'design' => 'Silver Chain',
            'category' => 'Silver Jewellery',
            'sub_category' => 'Chains',
            'metal_type' => 'silver',
            'gross_weight' => 100,
            'stone_weight' => 10,
            'purity' => 925,
            'making_charges' => 100,
            'stone_charges' => 50,
            'cost_price' => 999999,
            'selling_price' => 12000,
        ]);

        $response->assertCreated();
        $response->assertJsonFragment([
            'barcode' => 'RTL-MOB-001',
            'metal_type' => 'silver',
            'purity_label' => '925',
        ]);

        $this->assertSame(8475.0, round((float) $response->json('cost_price'), 2));

        $item = Item::withoutTenant()
            ->where('shop_id', $shop->id)
            ->where('barcode', 'RTL-MOB-001')
            ->firstOrFail();

        $this->assertSame(8475.0, round((float) $item->cost_price, 2));
    }

    public function test_web_retailer_pos_actions_are_blocked_when_rates_are_missing(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, [
            'barcode' => 'RTL-POS-001',
            'source' => 'purchase',
            'selling_price' => 10000,
            'metal_type' => 'gold',
        ]);

        $preview = $this->actingAs($user)->postJson(route('pos.preview'), [
            'item_id' => $item->id,
            'customer_id' => $customer->id,
        ]);

        $preview->assertStatus(422);
        $preview->assertJsonValidationErrors(['pricing']);

        $sell = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_ids' => [$item->id],
            'payments' => [
                ['mode' => 'cash', 'amount' => 10300],
            ],
        ]);

        $sell->assertStatus(422);
        $sell->assertJsonValidationErrors(['pricing']);
    }

    public function test_stock_import_preview_requires_current_day_rates(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $service = app(BulkImportService::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Today\'s retailer metal rates are missing');

        TenantContext::runFor($shop->id, function () use ($service, $shop, $user): void {
            $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_STOCK,
                UploadedFile::fake()->createWithContent(
                    'retailer-stock.csv',
                    implode("\n", [
                        'barcode,category,sub_category,metal_type,gross_weight,stone_weight,purity,making_charge,stone_charge,huid,vendor_name,design,selling_price',
                        'RTL-IMP-001,Gold Jewellery,Rings,gold,8.500,0.200,22,1200,500,AB1234CD5678,Kumar Jewellers,Imported Ring,42000',
                    ])
                )
            );
        });
    }
}

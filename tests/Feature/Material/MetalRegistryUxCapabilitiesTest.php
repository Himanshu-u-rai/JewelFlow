<?php

namespace Tests\Feature\Material;

use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MetalRegistryUxCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function assertThrowsForUnsupportedMetal(callable $fn): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $fn();
    }

    private function insertShopEnabledMetal(int $shopId, string $metalType, bool $enabled): void
    {
        DB::table('shop_enabled_metals')->insert([
            'shop_id' => $shopId,
            'metal_type' => $metalType,
            // Use a raw boolean literal to avoid Laravel/driver type coercion.
            'enabled' => DB::raw($enabled ? 'TRUE' : 'FALSE'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }



    public function test_uxItemCreationDefault_returns_expected_values(): void
    {
        $this->assertSame('rate_derived', MetalRegistry::uxItemCreationDefault('gold'));
        $this->assertSame('rate_derived', MetalRegistry::uxItemCreationDefault('silver'));
        $this->assertSame('piece_price', MetalRegistry::uxItemCreationDefault('platinum'));
        $this->assertSame('piece_price', MetalRegistry::uxItemCreationDefault('copper'));
    }

    public function test_uxItemCreationDefault_throws_for_unknown_metal(): void
    {
        $this->expectException(\LogicException::class);
        MetalRegistry::uxItemCreationDefault('unknown-metal');
    }

    public function test_uxItemCreationDefault_throws_for_empty_string(): void
    {
        $this->expectException(\LogicException::class);
        MetalRegistry::uxItemCreationDefault('');
    }


    public function test_uxRatesDashboardVisible_returns_true_only_for_gold_and_silver(): void
    {
        $this->assertTrue(MetalRegistry::uxRatesDashboardVisible('gold'));
        $this->assertTrue(MetalRegistry::uxRatesDashboardVisible('silver'));
        $this->assertFalse(MetalRegistry::uxRatesDashboardVisible('platinum'));
        $this->assertFalse(MetalRegistry::uxRatesDashboardVisible('copper'));
    }

    public function test_uxItemPickerVisible_gold_and_silver_true_regardless_of_shop_enabled_metals(): void
    {
        $shopId = (int) DB::table('shops')->insertGetId([
            'name' => 'UxCapabilitiesTest Shop ' . uniqid(),
            'phone' => '+91-0000000000',
            'owner_first_name' => 'Test',
            'owner_last_name' => 'Owner',
            'owner_mobile' => '+91-0000000000',
            'shop_code' => 'UXSHOP' . substr(uniqid(), -6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Ensure test isolation: set enabledMetals explicitly for this shop.
        $this->insertShopEnabledMetal($shopId, 'platinum', false);
        $this->insertShopEnabledMetal($shopId, 'copper', false);
        $this->insertShopEnabledMetal($shopId, 'gold', true);
        $this->insertShopEnabledMetal($shopId, 'silver', true);


        $this->assertTrue(MetalRegistry::uxItemPickerVisible('gold', $shopId));
        $this->assertTrue(MetalRegistry::uxItemPickerVisible('silver', $shopId));
    }

    public function test_uxItemPickerVisible_platinum_and_copper_respect_shop_enabled_metals_enabled_flag(): void
    {
        $shopId = (int) DB::table('shops')->insertGetId([
            'name' => 'UxCapabilitiesTest Shop ' . uniqid(),
            'phone' => '+91-0000000001',
            'owner_first_name' => 'Test',
            'owner_last_name' => 'Owner',
            'owner_mobile' => '+91-0000000001',
            'shop_code' => 'UXSHOP' . substr(uniqid(), -6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertShopEnabledMetal($shopId, 'platinum', false);
        $this->insertShopEnabledMetal($shopId, 'copper', false);
        $this->insertShopEnabledMetal($shopId, 'gold', true);
        $this->insertShopEnabledMetal($shopId, 'silver', true);


        $this->assertFalse(MetalRegistry::uxItemPickerVisible('platinum', $shopId));
        $this->assertFalse(MetalRegistry::uxItemPickerVisible('copper', $shopId));

        // Flip both to enabled.
        DB::table('shop_enabled_metals')
            ->where('shop_id', $shopId)
            ->whereIn('metal_type', ['platinum', 'copper'])
            ->update(['enabled' => DB::raw('TRUE'), 'updated_at' => now()]);


        MetalRegistry::clearShopCache($shopId);

        $this->assertTrue(MetalRegistry::uxItemPickerVisible('platinum', $shopId));
        $this->assertTrue(MetalRegistry::uxItemPickerVisible('copper', $shopId));
    }

    public function test_uxCustomerRateDisplayable_and_uxVaultPrimary_match_gold_and_silver_rule(): void
    {
        foreach (['gold', 'silver'] as $metal) {
            $this->assertTrue(MetalRegistry::uxCustomerRateDisplayable($metal));
            $this->assertTrue(MetalRegistry::uxVaultPrimary($metal));
        }

        foreach (['platinum', 'copper'] as $metal) {
            $this->assertFalse(MetalRegistry::uxCustomerRateDisplayable($metal));
            $this->assertFalse(MetalRegistry::uxVaultPrimary($metal));
        }
    }

    public function test_uxGramReconciliationDefault_matches_isReconciliationEligible_for_supported_metals(): void
    {
        foreach (['gold', 'silver', 'platinum', 'copper'] as $metal) {
            $this->assertSame(
                MetalRegistry::isReconciliationEligible($metal),
                MetalRegistry::uxGramReconciliationDefault($metal)
            );
        }
    }
}


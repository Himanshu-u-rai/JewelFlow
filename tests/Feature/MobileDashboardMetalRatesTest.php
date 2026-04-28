<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class MobileDashboardMetalRatesTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_mobile_dashboard_returns_owner_entered_daily_metal_rates(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user, [
            'gold_24k_rate_per_gram' => 7350,
            'silver_999_rate_per_gram' => 96.5,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'today',
            'counts',
            'alerts',
            'trend',
            'is_retailer',
            'metal_rates' => [
                'gold',
                'silver',
                'gold_rate',
                'silver_rate',
                'business_date',
                'source',
                'is_set',
            ],
        ]);
        $response->assertJsonPath('metal_rates.gold', 7350);
        $response->assertJsonPath('metal_rates.silver', 96.5);
        $response->assertJsonPath('metal_rates.gold_rate', 7350);
        $response->assertJsonPath('metal_rates.silver_rate', 96.5);
        $response->assertJsonPath('metal_rates.business_date', now()->toDateString());
        $response->assertJsonPath('metal_rates.source', 'owner_daily_rates');
        $response->assertJsonPath('metal_rates.is_set', true);
    }

    public function test_mobile_dashboard_returns_null_metal_rates_when_owner_has_not_set_today_rates(): void
    {
        [$user] = $this->createRetailerTenant();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'today',
            'counts',
            'alerts',
            'trend',
            'is_retailer',
            'metal_rates',
        ]);
        $response->assertJsonPath('metal_rates.gold', null);
        $response->assertJsonPath('metal_rates.silver', null);
        $response->assertJsonPath('metal_rates.gold_rate', null);
        $response->assertJsonPath('metal_rates.silver_rate', null);
        $response->assertJsonPath('metal_rates.business_date', null);
        $response->assertJsonPath('metal_rates.source', 'owner_daily_rates');
        $response->assertJsonPath('metal_rates.is_set', false);
    }
}

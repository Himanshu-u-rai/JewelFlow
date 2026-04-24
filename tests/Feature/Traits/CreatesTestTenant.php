<?php

namespace Tests\Feature\Traits;

use App\Models\Customer;
use App\Models\Item;
use App\Models\MetalLot;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopBillingSettings;
use App\Models\User;
use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

trait CreatesTestTenant
{
    protected function skipIfNotPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('These tests require PostgreSQL.');
        }
    }

    protected function createPlatformAdmin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'name' => 'Platform Admin',
            'email' => 'admin' . fake()->unique()->numberBetween(100, 99999) . '@example.com',
            'mobile_number' => '9' . fake()->unique()->numerify('#########'),
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    protected function createPlan(string $shopType = 'manufacturer'): Plan
    {
        return Plan::create([
            'code' => $shopType . '_basic_' . fake()->unique()->numberBetween(100, 99999),
            'name' => 'Basic',
            'price_monthly' => 999,
            'grace_days' => 5,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
        ]);
    }

    protected function createShop(string $shopType = 'manufacturer'): Shop
    {
        return Shop::create([
            'name' => 'Test Shop',
            'shop_type' => $shopType,
            'phone' => '9000000000',
            'owner_first_name' => 'Owner',
            'owner_last_name' => 'Test',
            'owner_mobile' => '9' . fake()->unique()->numerify('#########'),
            'gst_rate' => 3.00,
            'wastage_recovery_percent' => 100.00,
            'access_mode' => 'active',
            'is_active' => true,
        ]);
    }

    protected function createOwnerRole(int $shopId): Role
    {
        $role = new Role();
        $role->forceFill([
            'name' => 'owner',
            'display_name' => 'Owner',
            'shop_id' => $shopId,
        ]);
        $role->save();

        return $role;
    }

    protected function createOwnerUser(Shop $shop, Role $role): User
    {
        return User::factory()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    protected function createSubscription(int $shopId, PlatformAdmin $admin, Plan $plan): ShopSubscription
    {
        return ShopSubscription::create([
            'shop_id' => $shopId,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'grace_ends_at' => now()->addDays(37)->toDateString(),
            'updated_by_admin_id' => $admin->id,
        ]);
    }

    protected function createBillingSettings(int $shopId): ShopBillingSettings
    {
        $settings = new ShopBillingSettings();
        $settings->forceFill([
            'shop_id' => $shopId,
            'invoice_prefix' => 'INV-',
            'invoice_start_number' => 1001,
        ]);
        $settings->save();

        return $settings;
    }

    /**
     * Create a complete manufacturer tenant: shop, role, user, subscription, billing settings.
     */
    protected function createManufacturerTenant(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('manufacturer');
        $shop = $this->createShop('manufacturer');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $this->createSubscription($shop->id, $admin, $plan);
        $this->createBillingSettings($shop->id);

        return [$user, $shop];
    }

    /**
     * Create a complete retailer tenant: shop, role, user, subscription, billing settings.
     */
    protected function createRetailerTenant(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        $shop = $this->createShop('retailer');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $this->createSubscription($shop->id, $admin, $plan);
        $this->createBillingSettings($shop->id);

        return [$user, $shop];
    }

    protected function createCustomer(int $shopId, array $attrs = []): Customer
    {
        $customer = new Customer();
        $customer->forceFill(array_merge([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'mobile' => '98' . fake()->unique()->numerify('########'),
            'shop_id' => $shopId,
        ], $attrs));
        $customer->save();

        return $customer;
    }

    protected function createMetalLot(int $shopId, float $fineWeight = 100.0): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill([
            'shop_id' => $shopId,
            'source' => 'purchase',
            'purity' => 22.00,
            'fine_weight_total' => $fineWeight,
            'fine_weight_remaining' => $fineWeight,
            'cost_per_fine_gram' => 5000.00,
        ]);
        $lot->save();

        return $lot;
    }

    protected function createItem(int $shopId, ?int $metalLotId = null, array $attrs = []): Item
    {
        $item = new Item();
        $item->forceFill(array_merge([
            'shop_id' => $shopId,
            'barcode' => 'BC' . fake()->unique()->numerify('########'),
            'design' => 'Test Design',
            'category' => 'Ring',
            'gross_weight' => 10.000,
            'stone_weight' => 0.500,
            'net_metal_weight' => 9.500,
            'purity' => 22.00,
            'wastage' => 0.200,
            'making_charges' => 500.00,
            'stone_charges' => 200.00,
            'cost_price' => 50000.00,
            'selling_price' => 55000.00,
            'status' => 'in_stock',
            'metal_type' => 'gold',
            'metal_lot_id' => $metalLotId,
            'pricing_review_required' => false,
            'pricing_review_notes' => null,
            'source' => 'manufactured',
        ], $attrs));
        $item->save();

        return $item;
    }

    protected function seedRetailerPricing(Shop $shop, User|int $user, array $rates = []): void
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        $service = app(ShopPricingService::class);

        TenantContext::runFor($shop->id, function () use ($service, $shop, $userId, $rates): void {
            $service->saveTodayBaseRates($shop, $userId, array_merge([
                'gold_24k_rate_per_gram' => 7200,
                'silver_999_rate_per_gram' => 92,
            ], $rates));
        });
    }
}

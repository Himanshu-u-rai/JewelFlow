<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Import;
use App\Models\Invoice;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\BulkImportService;
use App\Services\InvoiceAccountingService;
use App\Services\ItemManufacturingService;
use App\Services\SalesService;
use App\Services\SubscriptionGateService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class SubscriptionServiceEnforcementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Subscription enforcement tests require PostgreSQL.');
        }

        config(['platform.enforce_subscriptions' => true]);
    }

    public function test_expired_subscription_blocks_sales_service_write_path(): void
    {
        [$shop, $user] = $this->createTenantWithSubscription('expired', now()->subDay()->toDateString());
        $this->actingAs($user);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Write operations are blocked');

        TenantContext::runFor($shop->id, function (): void {
            SalesService::sellItem(1, 1, 7200, 0, 0);
        });
    }

    public function test_expired_subscription_blocks_manufacturing_service_write_path(): void
    {
        [$shop, $user] = $this->createTenantWithSubscription('expired', now()->subDay()->toDateString());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Write operations are blocked');

        TenantContext::runFor($shop->id, function () use ($shop, $user): void {
            app(ItemManufacturingService::class)->manufacture($shop->id, $user->id, [
                'barcode' => 'SUB-BLOCK-001',
                'design' => 'Blocked Item',
                'category' => 'Gold Jewellery',
                'sub_category' => 'General',
                'gross_weight' => 10,
                'stone_weight' => 0,
                'purity' => 22,
                'wastage_percent' => 0,
                'making_charges' => 0,
                'stone_charges' => 0,
                'metal_lot_id' => 999999,
                'image' => null,
            ]);
        });
    }

    public function test_expired_subscription_blocks_bulk_import_execute(): void
    {
        [$shop, $user, $subscription] = $this->createTenantWithSubscription('active');
        $service = app(BulkImportService::class);

        TenantContext::runFor($shop->id, function (): void {
            $category = Category::create(['name' => 'Gold Jewellery']);
            SubCategory::create(['category_id' => $category->id, 'name' => 'Rings']);
        });

        $import = TenantContext::runFor($shop->id, function () use ($service, $shop, $user) {
            return $service->createPreview(
                $shop->id,
                $user->id,
                Import::TYPE_CATALOG,
                UploadedFile::fake()->createWithContent(
                    'catalog.csv',
                    implode("\n", [
                        'design_code,name,category,sub_category,default_purity,approx_weight,default_making,stone_type,notes',
                        'D-500,Subscription Test Ring,Gold Jewellery,Rings,22,10,500,None,Blocked check',
                    ])
                )
            );
        });

        $subscription->update([
            'status' => 'expired',
            'grace_ends_at' => now()->subDay()->toDateString(),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Write operations are blocked');

        TenantContext::runFor($shop->id, fn () => $service->execute($import->fresh(), Import::MODE_STRICT));
    }

    public function test_expired_subscription_blocks_invoice_finalize(): void
    {
        [$shop, $user] = $this->createTenantWithSubscription('expired', now()->subDay()->toDateString());
        $this->actingAs($user);

        $invoice = TenantContext::runFor($shop->id, function () {
            $customer = Customer::create([
                'first_name' => 'Test',
                'last_name' => 'Customer',
                'mobile' => '9000000011',
            ]);

            return Invoice::issue([
                'shop_id' => TenantContext::get(),
                'invoice_number' => 'INV-SUB-BLOCK-1',
                'customer_id' => $customer->id,
                'gold_rate' => 7000,
                'subtotal' => 0,
                'gst' => 0,
                'gst_rate' => 3,
                'wastage_charge' => 0,
                'total' => 0,
                'status' => Invoice::STATUS_DRAFT,
            ]);
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Write operations are blocked');

        TenantContext::runFor($shop->id, fn () => InvoiceAccountingService::finalizeDraft($invoice->fresh(), 3.0));
    }

    public function test_grace_subscription_allows_service_write_assertion(): void
    {
        [$shop] = $this->createTenantWithSubscription('grace', now()->addDays(5)->toDateString());

        TenantContext::runFor($shop->id, fn () => SubscriptionGateService::assertShopWritable($shop->id));

        $this->assertTrue(true);
    }

    public function test_cli_service_path_without_auth_still_blocks_expired_subscription(): void
    {
        [$shop] = $this->createTenantWithSubscription('expired', now()->subDay()->toDateString());

        auth()->logout();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Write operations are blocked');

        TenantContext::runFor($shop->id, fn () => SubscriptionGateService::assertShopWritable($shop->id));
    }

    private function createTenantWithSubscription(string $status, ?string $graceEndsAt = null): array
    {
        $shop = Shop::create([
            'name' => 'Subscription Gate Shop',
            'phone' => fake()->unique()->numerify('9#########'),
            'owner_first_name' => 'Sub',
            'owner_last_name' => 'Gate',
            'owner_mobile' => fake()->unique()->numerify('9#########'),
            'owner_email' => fake()->safeEmail(),
            'is_active' => true,
            'access_mode' => 'active',
        ]);

        $role = TenantContext::runFor($shop->id, fn () => Role::create([
            'name' => 'owner',
            'display_name' => 'Owner',
            'description' => 'Shop Owner',
        ]));

        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $admin = PlatformAdmin::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'name' => 'Platform Admin',
            'email' => 'platform' . fake()->unique()->numberBetween(1000, 999999) . '@example.com',
            'mobile_number' => '9' . fake()->unique()->numerify('#########'),
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'code' => 'sub-gate-' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Basic',
            'price_monthly' => 999,
            'grace_days' => 5,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
        ]);

        $subscription = ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
            'grace_ends_at' => $graceEndsAt,
            'updated_by_admin_id' => $admin->id,
        ]);

        return [$shop, $user, $subscription];
    }
}

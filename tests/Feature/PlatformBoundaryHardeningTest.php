<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class PlatformBoundaryHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Platform boundary hardening tests require PostgreSQL.');
        }

        Route::middleware(['api', 'auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])->group(function () {
            Route::get('/api/_control/read', fn () => response()->json(['ok' => true]));
            Route::post('/api/_control/write', fn () => response()->json(['ok' => true]));
        });
    }

    public function test_tenant_user_cannot_access_admin_routes(): void
    {
        $tenant = $this->createTenantUserWithSubscription('active');

        $response = $this->actingAs($tenant)->get('/admin');

        $response->assertRedirect(route('admin.login', absolute: false));
    }

    public function test_platform_admin_cannot_access_tenant_routes_without_tenant_guard(): void
    {
        $platformAdmin = $this->createPlatformAdmin();

        $response = $this->actingAs($platformAdmin, 'platform_admin')
            ->getJson('/api/_control/read');

        $response->assertStatus(401);
    }

    public function test_last_super_admin_cannot_be_deleted(): void
    {
        $platformAdmin = $this->createPlatformAdmin();

        $this->expectException(LogicException::class);
        $platformAdmin->delete();
    }

    public function test_expired_subscription_past_grace_blocks_tenant_access(): void
    {
        [$tenant, $shop] = $this->createTenantWithShop();
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan();

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'expired',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->subDay()->toDateString(),
            'updated_by_admin_id' => $admin->id,
        ]);

        $response = $this->actingAs($tenant)->getJson('/api/_control/read');

        $response->assertStatus(403);
        $this->assertSame('suspended', $shop->fresh()->access_mode);
    }

    public function test_platform_audit_log_rejects_update_and_delete(): void
    {
        $admin = $this->createPlatformAdmin();
        $id = DB::table('platform_audit_logs')->insertGetId([
            'actor_admin_id' => $admin->id,
            'action' => 'test.action',
            'target_type' => 'test',
            'target_id' => 1,
            'before' => json_encode(['a' => 1]),
            'after' => json_encode(['a' => 2]),
            'reason' => 'test',
            'created_at' => now(),
        ]);

        try {
            DB::table('platform_audit_logs')->where('id', $id)->update(['reason' => 'tamper']);
            $this->fail('Expected update on platform_audit_logs to be blocked.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('platform_audit_logs')->where('id', $id)->delete();
            $this->fail('Expected delete on platform_audit_logs to be blocked.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_suspended_shop_blocks_write_routes(): void
    {
        [$tenant, $shop] = $this->createTenantWithShop();
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan();

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'cancelled',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
            'cancelled_at' => now(),
            'updated_by_admin_id' => $admin->id,
        ]);

        $response = $this->actingAs($tenant)->postJson('/api/_control/write', ['x' => 1]);
        $response->assertStatus(403);
    }

    public function test_read_only_shop_blocks_writes_but_allows_reads(): void
    {
        [$tenant, $shop] = $this->createTenantWithShop();
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan();

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'read_only',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'updated_by_admin_id' => $admin->id,
        ]);

        $read = $this->actingAs($tenant)->getJson('/api/_control/read');
        $write = $this->actingAs($tenant)->postJson('/api/_control/write', ['x' => 1]);

        $read->assertOk();
        $write->assertStatus(423);
        $this->assertSame('read_only', $shop->fresh()->access_mode);
    }

    private function createPlatformAdmin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'name' => 'Platform Admin',
            'email' => 'platform' . fake()->unique()->numberBetween(100, 99999) . '@example.com',
            'mobile_number' => '9' . fake()->unique()->numerify('#########'),
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function createPlan(): Plan
    {
        return Plan::create([
            'code' => 'basic-' . fake()->unique()->numberBetween(100, 99999),
            'name' => 'Basic',
            'price_monthly' => 999,
            'grace_days' => 5,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
        ]);
    }

    private function createTenantUserWithSubscription(string $status): User
    {
        [$user, $shop] = $this->createTenantWithShop();
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan();

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'grace_ends_at' => now()->addDays(7)->toDateString(),
            'updated_by_admin_id' => $admin->id,
        ]);

        return $user;
    }

    private function createTenantWithShop(): array
    {
        $shop = Shop::create([
            'name' => 'Tenant Shop',
            'phone' => '9000000000',
            'owner_first_name' => 'Owner',
            'owner_last_name' => 'Test',
            'owner_mobile' => '9000000001',
            'access_mode' => 'active',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'is_active' => true,
        ]);

        return [$user, $shop];
    }
}

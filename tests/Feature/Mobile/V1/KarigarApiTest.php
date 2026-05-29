<?php

namespace Tests\Feature\Mobile\V1;

use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M9 — Karigar + JobOrder mobile API contract tests.
 *
 * Covers: karigar list/show (read), job-order list/show (read),
 * cross-shop isolation, envelope shape, idempotency on mutations.
 * Service-level business logic (lot locking, wastage, receipt item
 * creation) is exercised by the service's own tests; here we verify
 * the HTTP contract only.
 */
class KarigarApiTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        MetalRegistry::clearShopCache();
        parent::tearDown();
    }

    private function grant(\App\Models\User $user, string ...$perms): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($perms as $p) {
            $role->givePermission($p);
        }
    }

    private function idempotency(string $tag = ''): array
    {
        return ['X-Idempotency-Key' => 'karigar-' . $tag . '-' . uniqid()];
    }

    private function makeKarigar(int $shopId, string $name = 'Ramesh'): int
    {
        return DB::table('karigars')->insertGetId([
            'shop_id'                  => $shopId,
            'name'                     => $name,
            'mobile'                   => '9' . str_pad(rand(100000000, 999999999), 9, '0'),
            'is_active'                => DB::raw('true'),
            'default_wastage_percent'  => 2.5,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    public function test_karigars_list_requires_auth(): void
    {
        $this->getJson('/api/mobile/v1/karigars')->assertStatus(401);
    }

    public function test_karigars_list_is_shop_scoped(): void
    {
        [$userA, $shopA] = $this->createRetailerTenant();
        [$userB, $shopB] = $this->createRetailerTenant();
        $this->grant($userA, 'karigar.view');

        $this->makeKarigar((int) $shopB->id, 'Shop B Karigar');

        Sanctum::actingAs($userA);
        \App\Support\TenantContext::set((int) $shopA->id);

        $response = $this->getJson('/api/mobile/v1/karigars');
        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_karigar_show_returns_outstanding_fine(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'karigar.view');
        $karigarId = $this->makeKarigar((int) $shop->id);

        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        $response = $this->getJson('/api/mobile/v1/karigars/' . $karigarId);
        $response->assertOk();
        $this->assertArrayHasKey('outstanding_fine_weight', $response->json('data'));
        $this->assertEquals(0.0, $response->json('data.outstanding_fine_weight'));
    }

    public function test_karigar_show_404_for_wrong_shop(): void
    {
        [$userA, $shopA] = $this->createRetailerTenant();
        [$userB, $shopB] = $this->createRetailerTenant();
        $this->grant($userA, 'karigar.view');
        $karigarId = $this->makeKarigar((int) $shopB->id);

        Sanctum::actingAs($userA);
        \App\Support\TenantContext::set((int) $shopA->id);

        $this->getJson('/api/mobile/v1/karigars/' . $karigarId)->assertStatus(404);
    }

    public function test_job_orders_list_requires_auth(): void
    {
        $this->getJson('/api/mobile/v1/job-orders')->assertStatus(401);
    }

    public function test_job_orders_list_is_shop_scoped_and_paginated(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'job_order.view');

        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        $response = $this->getJson('/api/mobile/v1/job-orders');
        $response->assertOk();
        $this->assertArrayHasKey('data', $response->json('data'));
        $this->assertArrayHasKey('pagination', $response->json('data'));
        $this->assertArrayHasKey('has_more', $response->json('data.pagination'));
    }

    public function test_job_order_store_requires_idempotency_key(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'job_order.manage');
        Sanctum::actingAs($user);

        // No X-Idempotency-Key header.
        $response = $this->postJson('/api/mobile/v1/job-orders', [
            'karigar_id' => 1,
            'metal_type' => 'gold',
            'purity'     => 22,
            'issuances'  => [['metal_lot_id' => 1, 'gross_weight' => 5, 'fine_weight' => 4.58]],
        ]);

        $response->assertStatus(422);
        $this->assertSame('missing_idempotency_key', $response->json('errors.0.code'));
    }

    public function test_receipt_requires_idempotency_key(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'job_order.manage');
        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);

        // Plant a fake open job order.
        $karigarId = $this->makeKarigar((int) $shop->id);
        $joId = DB::table('job_orders')->insertGetId([
            'shop_id'                         => $shop->id,
            'karigar_id'                      => $karigarId,
            'job_order_number'                => 'JO-TEST-001',
            'challan_number'                  => 'CH-001',
            'metal_type'                      => 'gold',
            'purity'                          => 22,
            'issued_gross_weight'             => 50,
            'issued_fine_weight'              => 45.83,
            'expected_return_fine_weight'     => 44.93,
            'allowed_wastage_percent'         => 2,
            'status'                          => 'issued',
            'issue_date'                      => now()->toDateString(),
            'created_at'                      => now(),
            'updated_at'                      => now(),
            'created_by_user_id'              => $user->id,
        ]);

        // No idempotency key.
        $response = $this->postJson('/api/mobile/v1/job-orders/' . $joId . '/receipt', [
            'items' => [['gross_weight' => 44, 'pieces' => 10]],
        ]);
        $response->assertStatus(422);
        $this->assertSame('missing_idempotency_key', $response->json('errors.0.code'));
    }
}

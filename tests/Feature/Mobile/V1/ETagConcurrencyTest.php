<?php

namespace Tests\Feature\Mobile\V1;

use App\Models\Customer;
use App\Models\Item;
use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M5 — Optimistic concurrency on mobile v1 item / customer mutations.
 *
 * The contract:
 *   - GET on a resource returns an ETag header.
 *   - PATCH WITHOUT `If-Match` → 428 precondition_required.
 *   - PATCH with a STALE ETag  → 412 precondition_failed.
 *   - PATCH with a CURRENT ETag → succeeds; response carries the NEW ETag.
 *   - Cross-shop access leaks nothing (404, not 403/412).
 *   - ETags are resource-typed (Item 42 ≠ Customer 42).
 */
class ETagConcurrencyTest extends TestCase
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

    private function makeItem(int $shopId): Item
    {
        // Bypass the BelongsToShop auto-fill: tests create rows before
        // (or for other shops than) the current Sanctum actor.
        $id = DB::table('items')->insertGetId([
            'shop_id'          => $shopId,
            'barcode'          => 'TEST-' . uniqid(),
            'design'           => 'Test Design',
            'category'         => 'Ring',
            'metal_type'       => 'gold',
            'purity'           => 22,
            'gross_weight'     => 5.0,
            'net_metal_weight' => 5.0,
            'selling_price'    => 50000,
            'cost_price'       => 45000,
            'status'           => 'in_stock',
            'share_token'      => (string) \Illuminate\Support\Str::ulid(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        return Item::withoutTenant()->findOrFail($id);
    }

    private function idempotency(string $tag = ''): array
    {
        return ['X-Idempotency-Key' => 'etag-test-' . $tag . '-' . uniqid()];
    }

    public function test_get_item_returns_etag_header(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.view');
        $item = $this->makeItem((int) $shop->id);

        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);
        $response = $this->getJson('/api/mobile/v1/items/' . $item->id);

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertStringStartsWith('"', $response->headers->get('ETag'));
    }

    public function test_patch_without_if_match_returns_428(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.view', 'inventory.edit');
        $item = $this->makeItem((int) $shop->id);

        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);
        $response = $this->withHeaders($this->idempotency('no-match'))
            ->patchJson('/api/mobile/v1/items/' . $item->id, ['selling_price' => 60000]);

        $response->assertStatus(428);
        $this->assertSame('precondition_required', $response->json('errors.0.code'));
    }

    public function test_patch_with_stale_etag_returns_412_and_does_not_update(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.view', 'inventory.edit');
        $item = $this->makeItem((int) $shop->id);

        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);
        $getResp = $this->getJson('/api/mobile/v1/items/' . $item->id);
        $staleEtag = $getResp->headers->get('ETag');

        // Simulate another cashier updating the row in the meantime.
        sleep(1);
        DB::table('items')->where('id', $item->id)->update([
            'selling_price' => 55000,
            'updated_at'    => now(),
        ]);

        \App\Support\TenantContext::set((int) $shop->id);
        $response = $this->withHeaders(array_merge(
            $this->idempotency('stale'),
            ['If-Match' => $staleEtag],
        ))->patchJson('/api/mobile/v1/items/' . $item->id, ['selling_price' => 99999]);

        $response->assertStatus(412);
        $this->assertSame('precondition_failed', $response->json('errors.0.code'));

        // Confirm the row was NOT clobbered by the 412'd request.
        $this->assertSame('55000.00', (string) DB::table('items')->where('id', $item->id)->value('selling_price'));
    }

    public function test_patch_with_current_etag_succeeds_and_returns_new_etag(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.view', 'inventory.edit');
        $item = $this->makeItem((int) $shop->id);

        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);
        $getResp = $this->getJson('/api/mobile/v1/items/' . $item->id);
        $currentEtag = $getResp->headers->get('ETag');

        sleep(1);
        \App\Support\TenantContext::set((int) $shop->id);
        $patchResp = $this->withHeaders(array_merge(
            $this->idempotency('current'),
            ['If-Match' => $currentEtag],
        ))->patchJson('/api/mobile/v1/items/' . $item->id, ['selling_price' => 60000]);

        $patchResp->assertOk();
        $newEtag = $patchResp->headers->get('ETag');
        $this->assertNotEmpty($newEtag);
        $this->assertNotSame($currentEtag, $newEtag, 'ETag must change after a successful update.');
    }

    public function test_etag_is_resource_typed_across_models(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'inventory.view', 'customers.view');
        $item = $this->makeItem((int) $shop->id);
        // Force the customer id to match the item id where possible to prove
        // the class basename keeps the ETags distinct.
        $cid = DB::table('customers')->insertGetId([
            'shop_id'    => $shop->id,
            'first_name' => 'A',
            'last_name'  => 'B',
            'mobile'     => '9000000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = Customer::withoutTenant()->findOrFail($cid);

        Sanctum::actingAs($user);
        \App\Support\TenantContext::set((int) $shop->id);
        $itemEtag = $this->getJson('/api/mobile/v1/items/' . $item->id)->headers->get('ETag');
        $custEtag = $this->getJson('/api/mobile/v1/customers/' . $customer->id)->headers->get('ETag');

        $this->assertNotSame($itemEtag, $custEtag, 'Customer and Item ETags must differ even at the same id.');
    }

    public function test_cross_shop_access_returns_404_not_412(): void
    {
        [$userA, $shopA] = $this->createRetailerTenant();
        [$userB, $shopB] = $this->createRetailerTenant();
        $this->grant($userA, 'inventory.view', 'inventory.edit');
        $this->grant($userB, 'inventory.view', 'inventory.edit');

        $itemB = $this->makeItem((int) $shopB->id);

        Sanctum::actingAs($userA);
        \App\Support\TenantContext::set((int) $shopA->id);
        $response = $this->getJson('/api/mobile/v1/items/' . $itemB->id);
        $response->assertStatus(404);
        // Must NOT leak existence with a different status code.
        $this->assertContains((string) $response->json('errors.0.code'), ['not_found', 'unauthorized', 'permission_denied']);
    }
}

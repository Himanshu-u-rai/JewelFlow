<?php

namespace Tests\Feature\Mobile\V1;

use App\Models\CashDrawerCheck;
use App\Models\CashTransaction;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 4 — Cash Book mobile API contract tests.
 *
 * Covers: auth gate, per-mode money-on-hand + ledger, manual entry, drawer
 * check (expected server-computed), shop scoping, envelope shape. Reuses the
 * same engine + write path as the web cashbook.
 */
class CashBookApiTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function grant(\App\Models\User $user, string ...$perms): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($perms as $p) {
            $role->givePermission($p);
        }
    }

    private function idem(string $tag = ''): array
    {
        return ['X-Idempotency-Key' => 'cb-' . $tag . '-' . uniqid()];
    }

    private function seedCash(int $shopId, int $userId, string $type, float $amount, string $mode): void
    {
        CashTransaction::record([
            'shop_id' => $shopId, 'user_id' => $userId, 'type' => $type, 'amount' => $amount,
            'source_type' => 'manual', 'source_id' => null, 'payment_mode' => $mode, 'description' => 'seed',
        ]);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/mobile/v1/cashbook')->assertStatus(401);
    }

    public function test_index_returns_per_mode_balances_and_envelope(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->grant($user, 'cash.view');
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $this->seedCash($shop->id, $user->id, 'in', 1000, 'cash');
        $this->seedCash($shop->id, $user->id, 'in', 400, 'upi');

        $res = $this->getJson('/api/mobile/v1/cashbook');
        $res->assertOk();
        // envelope
        $this->assertArrayHasKey('data', $res->json());
        $this->assertArrayHasKey('meta', $res->json());
        $this->assertArrayHasKey('errors', $res->json());
        // cash drawer figure
        $this->assertEqualsWithDelta(1000, $res->json('data.money_on_hand.cash.closing'), 0.01);
        // total = cash + upi
        $this->assertEqualsWithDelta(1400, $res->json('data.money_on_hand.total.closing'), 0.01);
        // ledger present
        $this->assertGreaterThanOrEqual(2, count($res->json('data.ledger')));
    }

    public function test_manual_entry_records_mode(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->grant($user, 'cash.view', 'cash.create');
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $res = $this->withHeaders($this->idem('store'))->postJson('/api/mobile/v1/cashbook', [
            'type' => 'out', 'amount' => 1200, 'source_type' => 'rent', 'payment_mode' => 'bank',
        ]);
        $res->assertStatus(201);
        $this->assertSame('bank', $res->json('data.payment_mode'));
        $this->assertSame('out', $res->json('data.type'));

        TenantContext::set((int) $shop->id);
        $this->assertSame(1, CashTransaction::where('shop_id', $shop->id)->count());
    }

    public function test_manual_entry_defaults_to_cash(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->grant($user, 'cash.view', 'cash.create');
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $res = $this->withHeaders($this->idem('default'))->postJson('/api/mobile/v1/cashbook', [
            'type' => 'in', 'amount' => 500, 'source_type' => 'other_income',
        ]);
        $res->assertStatus(201);
        $this->assertSame('cash', $res->json('data.payment_mode'));
    }

    public function test_drawer_check_expected_is_server_computed(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->grant($user, 'cash.view', 'cash.create');
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);
        $this->seedCash($shop->id, $user->id, 'in', 800, 'cash');

        // Spoofed expected_cash must be ignored; difference computed from the truth.
        $res = $this->withHeaders($this->idem('drawer'))->postJson('/api/mobile/v1/cashbook/drawer-check', [
            'counted_cash' => 750, 'expected_cash' => 999999, 'note' => 'eod',
        ]);
        $res->assertStatus(201);
        $this->assertEqualsWithDelta(800, $res->json('data.expected_cash'), 0.01);
        $this->assertEqualsWithDelta(750, $res->json('data.counted_cash'), 0.01);
        $this->assertEqualsWithDelta(-50, $res->json('data.difference'), 0.01);
        $this->assertSame('short', $res->json('data.status'));

        TenantContext::set((int) $shop->id);
        $this->assertSame(1, CashDrawerCheck::where('shop_id', $shop->id)->count());
    }

    public function test_drawer_context_returns_expected_and_history(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->grant($user, 'cash.view', 'cash.create');
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);
        $this->seedCash($shop->id, $user->id, 'in', 300, 'cash');

        $this->withHeaders($this->idem('h'))->postJson('/api/mobile/v1/cashbook/drawer-check', ['counted_cash' => 300]);

        $res = $this->getJson('/api/mobile/v1/cashbook/drawer-check');
        $res->assertOk();
        $this->assertEqualsWithDelta(300, $res->json('data.expected_cash'), 0.01);
        $this->assertGreaterThanOrEqual(1, count($res->json('data.recent_checks')));
    }

    public function test_create_requires_cash_create_permission(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        // The test owner is provisioned with ALL permissions; revoke cash.create
        // so the can:cash.create gate is actually exercised. cash.view stays.
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        $role->revokePermission('cash.create');
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $this->withHeaders($this->idem('forbidden'))->postJson('/api/mobile/v1/cashbook', [
            'type' => 'in', 'amount' => 100, 'source_type' => 'other_income',
        ])->assertStatus(403);
    }

    public function test_manual_entry_lands_in_callers_shop_only(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [, $shopB]       = $this->createManufacturerTenant();
        $this->grant($userA, 'cash.view', 'cash.create');
        Sanctum::actingAs($userA);
        TenantContext::set((int) $shopA->id);

        // shop_id comes from the authenticated user, never the request — a
        // spoofed shop_id in the body must be ignored.
        $this->withHeaders($this->idem('iso'))->postJson('/api/mobile/v1/cashbook', [
            'type' => 'in', 'amount' => 700, 'source_type' => 'other_income',
            'shop_id' => $shopB->id, // spoof attempt
            'user_id' => 999999,     // spoof attempt
        ])->assertStatus(201);

        // The row belongs to shop A, not shop B.
        $this->assertSame(1, CashTransaction::withoutGlobalScopes()->where('shop_id', $shopA->id)->count());
        $this->assertSame(0, CashTransaction::withoutGlobalScopes()->where('shop_id', $shopB->id)->count());
        // And it's attributed to the real caller, not the spoofed user_id.
        $row = CashTransaction::withoutGlobalScopes()->where('shop_id', $shopA->id)->first();
        $this->assertSame((int) $userA->id, (int) $row->user_id);
    }

    public function test_index_is_shop_scoped(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [$userB, $shopB] = $this->createManufacturerTenant();
        $this->grant($userA, 'cash.view');
        $this->seedCash($shopB->id, $userB->id, 'in', 5000, 'cash'); // other shop's money

        Sanctum::actingAs($userA);
        TenantContext::set((int) $shopA->id);

        $res = $this->getJson('/api/mobile/v1/cashbook');
        $res->assertOk();
        // Shop A has no cash; must not see shop B's 5000.
        $this->assertEqualsWithDelta(0, $res->json('data.money_on_hand.cash.closing'), 0.01);
        $this->assertSame([], $res->json('data.ledger'));
    }
}

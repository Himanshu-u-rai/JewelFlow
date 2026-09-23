<?php

namespace Tests\Feature\Security;

use App\Models\CashTransaction;
use App\Models\IdempotencyKey;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * XR-01 (S3-09 family) — authorization must run before an idempotent replay.
 *
 * Resolved middleware order (Router::gatherRouteMiddleware, priority-sorted),
 * measured on 7b1d709: on 15 of the 16 idempotency-protected mobile routes
 * EnsureIdempotency ran BEFORE any route-level authorization. Nine declare
 * their `can:` after the `mobile.idempotency` group. Cash Book and the session
 * revocations have no route-level check at all — their policy lives inside the
 * controller, which a replay never reaches. `uploads/intent` was the one route
 * already correct.
 *
 * So a user who performed a mutation, then lost the permission or the role for
 * it, could replay the same key and receive the stored success body. That is
 * a stale authorization decision served as current. Nothing was written again,
 * and nothing here crosses shops: claims are keyed by (shop, user, key), and
 * the different-shop control below shows that holds. It is not a cross-shop
 * exploit and is not described as one.
 *
 * Required: current authorization before both replay and fresh processing,
 * refusal without the stored body and without a write, the claim left intact,
 * and an authorized replay unchanged.
 */
class IdempotentReplayAuthorizationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function send(User $user, string $method, string $uri, string $key, array $body, array $headers = [])
    {
        Sanctum::actingAs($user->fresh());
        TenantContext::set((int) $user->shop_id);

        return $this->withHeaders(array_merge(['X-Idempotency-Key' => $key], $headers))->json($method, $uri, $body);
    }

    private function cashPayload(): array
    {
        return ['type' => 'in', 'amount' => 2500.00, 'source_type' => 'other', 'payment_mode' => 'cash', 'description' => 'Float top-up'];
    }

    private function cashRows(int $shopId): int
    {
        return CashTransaction::withoutTenant()->where('shop_id', $shopId)->count();
    }

    /** Move the user to a role named `staff` that still carries every permission. */
    private function downgradeToStaff(User $user): User
    {
        $role = TenantContext::runFor((int) $user->shop_id, function () use ($user) {
            $role = (new Role)->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $user->shop_id]);
            $role->save();
            $role->permissions()->sync(Permission::query()->pluck('id'));

            return $role;
        });
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    private function assertRefusedWithoutReplay($response, string $storedFragment): void
    {
        $response->assertStatus(403);
        $this->assertSame('permission_denied', $response->json('errors.0.code'));
        $this->assertNull($response->headers->get('X-Idempotent-Replay'), 'must not be served from the stored claim');
        $this->assertStringNotContainsString($storedFragment, $response->getContent(), 'the stored body must not be returned');
    }

    // ────────────────────────────────────────────────────────────────────
    // A route whose `can:` was declared after the idempotency group
    // ────────────────────────────────────────────────────────────────────

    private function patchItemOnce(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $item = $this->createItem((int) $shop->id, null, ['selling_price' => 1000]);

        TenantContext::set((int) $shop->id);
        Sanctum::actingAs($owner);
        $tag = $this->getJson("/api/mobile/v1/items/{$item->id}")->headers->get('ETag');

        $this->send($owner, 'PATCH', "/api/mobile/v1/items/{$item->id}", 'xr01-item-key', ['selling_price' => 2500], ['If-Match' => $tag])->assertOk();

        return [$owner, $shop, $item, $tag];
    }

    public function test_revoked_permission_refuses_the_replay_without_its_body_or_a_write(): void
    {
        [$owner, , $item, $tag] = $this->patchItemOnce();
        $updatedAt = $item->fresh()->updated_at;

        $this->grantOnlyPermissions($owner, ['inventory.view']);

        $replay = $this->send($owner, 'PATCH', "/api/mobile/v1/items/{$item->id}", 'xr01-item-key', ['selling_price' => 2500], ['If-Match' => $tag]);

        $this->assertRefusedWithoutReplay($replay, '2500');
        $this->assertEquals($updatedAt, $item->fresh()->updated_at, 'no write');
    }

    /** [CONTROL] Keys, claims and authorized replay are preserved. */
    public function test_the_claim_survives_and_replays_again_once_permission_is_restored(): void
    {
        [$owner, , $item, $tag] = $this->patchItemOnce();

        $this->grantOnlyPermissions($owner, ['inventory.view']);
        $this->send($owner, 'PATCH', "/api/mobile/v1/items/{$item->id}", 'xr01-item-key', ['selling_price' => 2500], ['If-Match' => $tag])->assertStatus(403);

        $this->assertSame(200, (int) IdempotencyKey::where('key', 'xr01-item-key')->value('response_status'), 'the claim is untouched by the refusal');

        $this->grantOnlyPermissions($owner, ['inventory.view', 'inventory.edit']);
        $replay = $this->send($owner, 'PATCH', "/api/mobile/v1/items/{$item->id}", 'xr01-item-key', ['selling_price' => 2500], ['If-Match' => $tag]);

        $replay->assertOk();
        $this->assertSame('true', $replay->headers->get('X-Idempotent-Replay'));
    }

    // ────────────────────────────────────────────────────────────────────
    // Cash Book — policy inside the controller, stricter than a permission
    // ────────────────────────────────────────────────────────────────────

    public function test_a_role_downgrade_refuses_the_cash_book_replay(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->send($owner, 'POST', '/api/mobile/v1/cashbook', 'xr01-cash-key', $this->cashPayload())->assertCreated();
        $this->assertSame(1, $this->cashRows((int) $shop->id));

        // Staff keep cash.create here, and Cash Book still refuses them: its
        // policy is owner-or-manager AND the permission.
        $staff = $this->downgradeToStaff($owner);
        $replay = $this->send($staff, 'POST', '/api/mobile/v1/cashbook', 'xr01-cash-key', $this->cashPayload());

        $this->assertRefusedWithoutReplay($replay, '2500');
        $this->assertSame('You do not have permission to access Cash Book.', $replay->json('errors.0.message'),
            'the same stable message Cash Book has always given');
        $this->assertSame(1, $this->cashRows((int) $shop->id), 'no second cash row');
    }

    public function test_a_role_downgrade_refuses_a_fresh_cash_book_write_the_same_way(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $staff = $this->downgradeToStaff($owner);

        $fresh = $this->send($staff, 'POST', '/api/mobile/v1/cashbook', 'xr01-cash-fresh', $this->cashPayload());

        $fresh->assertStatus(403);
        $this->assertSame('You do not have permission to access Cash Book.', $fresh->json('errors.0.message'));
        $this->assertSame(0, $this->cashRows((int) $shop->id));
        $this->assertNull(IdempotencyKey::where('key', 'xr01-cash-fresh')->first(), 'refused before any claim is staked');
    }

    /** [CONTROL] An authorized same-key retry still replays. */
    public function test_an_authorized_cash_book_retry_still_replays(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $first = $this->send($owner, 'POST', '/api/mobile/v1/cashbook', 'xr01-cash-ok', $this->cashPayload());
        $first->assertCreated();

        $replay = $this->send($owner, 'POST', '/api/mobile/v1/cashbook', 'xr01-cash-ok', $this->cashPayload());

        $replay->assertCreated();
        $this->assertSame('true', $replay->headers->get('X-Idempotent-Replay'));
        $this->assertSame($first->json('data.id') ?? $first->json('id'), $replay->json('data.id') ?? $replay->json('id'));
        $this->assertSame(1, $this->cashRows((int) $shop->id));
    }

    /** [CONTROL] The same key string in another shop is that shop's own request. */
    public function test_the_same_key_in_another_shop_never_receives_the_stored_body(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $a = $this->send($ownerA, 'POST', '/api/mobile/v1/cashbook', 'xr01-shared-key', $this->cashPayload());
        $b = $this->send($ownerB, 'POST', '/api/mobile/v1/cashbook', 'xr01-shared-key', $this->cashPayload());

        $b->assertCreated();
        $this->assertNull($b->headers->get('X-Idempotent-Replay'), "shop B's request is processed, not replayed from shop A");
        $this->assertSame(1, $this->cashRows((int) $shopA->id));
        $this->assertSame(1, $this->cashRows((int) $shopB->id));
        $this->assertNotSame($a->getContent(), $b->getContent());
    }

    // ────────────────────────────────────────────────────────────────────
    // Session revocation — policy inside the controller
    // ────────────────────────────────────────────────────────────────────

    public function test_a_downgraded_owner_cannot_replay_a_revoke_all_sessions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $clerkRole = TenantContext::runFor((int) $shop->id, function () use ($shop) {
            $role = (new Role)->forceFill(['name' => 'clerk', 'display_name' => 'Clerk', 'shop_id' => $shop->id]);
            $role->save();

            return $role;
        });
        $staffMember = $this->createOwnerUser($shop, $clerkRole);

        $this->send($owner, 'DELETE', '/api/mobile/v1/sessions', 'xr01-sessions-key', ['user_id' => $staffMember->id])->assertOk();

        $downgraded = $this->downgradeToStaff($owner);
        $replay = $this->send($downgraded, 'DELETE', '/api/mobile/v1/sessions', 'xr01-sessions-key', ['user_id' => $staffMember->id]);

        $replay->assertStatus(403);
        $this->assertNull($replay->headers->get('X-Idempotent-Replay'));
    }
}

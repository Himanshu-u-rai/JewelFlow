<?php

namespace Tests\Feature\Security;

use App\Models\Item;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The single line the whole tenancy design rests on, and nothing bound it.
 *
 * ─── What this pins ───────────────────────────────────────────────────────
 *
 * `BelongsToShop::bootBelongsToShop` resolves a shop id and, when it cannot,
 * takes this branch:
 *
 *     $builder->whereRaw('1 = 0');
 *
 * That is a DENY, not an absence of a filter. The difference is the entire
 * security posture of every background context in the application:
 *
 *   * DENY (today)  a queued job that forgets `TenantContext::runFor` reads
 *                   zero rows and, loudly, does nothing.
 *   * NO FILTER     the same job reads EVERY shop's rows and cannot tell.
 *
 * Both compile. Both pass every other test in this suite. The only thing
 * separating them is a branch no assertion referenced until this file.
 *
 * ─── Why this is not a count-raiser ───────────────────────────────────────
 *
 * Verified by mutation, not assumed. Replacing the `whereRaw('1 = 0')` with a
 * bare `return;` — the shape a future reader reaches for when the 1=0 looks
 * like dead weight — leaves the application working, leaves the rest of the
 * band green, and silently converts every context-free query in the codebase
 * into a cross-tenant read. The mutation figures are in the handoff at §7e.
 *
 * ─── Why the test environment is the right environment ────────────────────
 *
 * `resolveTenantShopId()` short-circuits on `app()->runningInConsole()` BEFORE
 * consulting `Auth`, so under PHPUnit a cleared TenantContext reproduces the
 * queue worker's state exactly: no context, no usable auth fallback. This is
 * the one place where that test-environment characteristic is an asset rather
 * than an obstacle to work around.
 *
 * ─── Stated limitation ────────────────────────────────────────────────────
 *
 * This binds the READ side only. The `creating` hook fills `shop_id` from
 * context only when the attribute is empty, so a context-free create is caught
 * by the column's NOT NULL constraint rather than by the trait. That is the
 * database's guarantee, not this trait's, and it is pinned where the schema is
 * pinned — not duplicated here.
 */
class TenantScopeFailClosedTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /**
     * Two shops, one item each.
     *
     * @return array{0: int, 1: int}
     */
    private function twoTenantsWithAnItemEach(): array
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $this->createItem((int) $shopA->id);
        $this->createItem((int) $shopB->id);

        TenantContext::clear();

        return [(int) $shopA->id, (int) $shopB->id];
    }

    // ────────────────────────────────────────────────────────────────────
    // Precondition — so "sees nothing" cannot mean "there was nothing"
    // ────────────────────────────────────────────────────────────────────

    /**
     * Load-bearing. Without it, every denial assertion below is equally well
     * explained by an empty table, and the file would stay green even if the
     * fixture silently stopped inserting.
     */
    public function test_precondition_both_shops_hold_a_row(): void
    {
        [$shopA, $shopB] = $this->twoTenantsWithAnItemEach();

        $this->assertSame(1, Item::withoutTenant()->where('shop_id', $shopA)->count());
        $this->assertSame(1, Item::withoutTenant()->where('shop_id', $shopB)->count());
    }

    // ────────────────────────────────────────────────────────────────────
    // The invariant
    // ────────────────────────────────────────────────────────────────────

    /**
     * [PINS THE REPAIR-RELEVANT INVARIANT] No context ⇒ no rows.
     *
     * This is the state a queued job runs in. The rows exist — the previous
     * test proves it — and a scoped query still returns none of them.
     */
    public function test_a_query_with_no_tenant_context_reads_nothing_at_all(): void
    {
        $this->twoTenantsWithAnItemEach();

        $this->assertSame(
            0,
            Item::query()->count(),
            'BelongsToShop must fail CLOSED with no tenant context. A non-zero count here means '
                . 'the scope degraded to "no filter", and every context-free background query in '
                . 'the application is now a cross-tenant read.',
        );
    }

    /**
     * [POSITIVE CONTROL] The denial is the missing context, not a broken scope.
     *
     * Without this, a scope that returned nothing under ALL conditions would
     * pass the test above while having destroyed the application.
     */
    public function test_control_explicit_context_reads_that_shop_and_only_that_shop(): void
    {
        [$shopA, $shopB] = $this->twoTenantsWithAnItemEach();

        $seen = TenantContext::runFor($shopA, fn () => Item::query()->pluck('shop_id')->all());

        $this->assertSame([$shopA], $seen, 'Explicit context must read exactly its own shop.');
        $this->assertNotContains($shopB, $seen, 'and must not reach the neighbouring shop.');
    }

    /**
     * The context does not leak past `runFor`.
     *
     * `runFor` restores the previous value in a `finally`, so a job that
     * handles one tenant cannot leave the next handler holding its id. The
     * assertion is the deny state again, which is the correct thing to return
     * to: before any `runFor`, there was no context.
     */
    public function test_context_does_not_survive_the_block_that_set_it(): void
    {
        [$shopA] = $this->twoTenantsWithAnItemEach();

        TenantContext::runFor($shopA, fn () => Item::query()->count());

        $this->assertNull(TenantContext::get(), 'runFor must restore the previous context.');
        $this->assertSame(
            0,
            Item::query()->count(),
            'and the scope must return to denying once the block has exited.',
        );
    }
}

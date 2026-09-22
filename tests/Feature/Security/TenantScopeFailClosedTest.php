<?php

namespace Tests\Feature\Security;

use App\Models\Item;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Missing tenant context must deny, not unfilter — pinned directly at the scope.
 *
 * ─── What this pins ───────────────────────────────────────────────────────
 *
 * `BelongsToShop::bootBelongsToShop` resolves a shop id and, when it cannot,
 * takes this branch:
 *
 *     $builder->whereRaw('1 = 0');
 *
 * That is a DENY, not an absence of a filter. For a `BelongsToShop` model read
 * with NO context — a queued job that forgot `TenantContext::runFor`, a console
 * path — the difference is zero rows versus every shop's rows.
 *
 * ─── What this does NOT pin ───────────────────────────────────────────────
 *
 *   * WRONG or STALE non-null context. The scope trusts whatever id is set; a
 *     job handed the wrong shop id reads that shop faithfully. Fail-closed says
 *     nothing about it. Lifecycle evidence for that half is `runFor`'s restore
 *     (last test below) and `EnsureTenantUser`'s `finally`; see handoff §7e.
 *   * Anything that never reaches the scope: raw `DB::table()`, models without
 *     the trait, `withoutTenant()` / `withoutGlobalScope(s)()`.
 *   * The WRITE side. `creating` fills `shop_id` only when empty; a context-free
 *     create on a trait model is refused by the column instead — every
 *     `shop_id` on a trait model is NOT NULL (the only four nullable ones,
 *     `metal_rates`, `shop_subscriptions`, `subscription_events`, `users`,
 *     belong to models without the trait). Schema query, not a test.
 *
 * ─── Evidence, including a correction ─────────────────────────────────────
 *
 * Mutation `whereRaw('1 = 0')` → bare `return;` kills the two denial tests
 * here; the precondition and the positive control correctly survive.
 *
 * This is NOT the first test to bind the branch, as first claimed. Run under
 * the same mutation, `tests/Feature/Security` also fails
 * `InvoicePaymentRetryRepairTest::test_p10_missing_tenant_context_...`, which
 * binds it indirectly through one route. This file adds direct coverage of the
 * scope itself, independent of any route. The full suite was not run under the
 * mutation. Figures in handoff §7e.
 *
 * `resolveTenantShopId()` checks `runningInConsole()` before `Auth`, so under
 * PHPUnit a cleared TenantContext reproduces the no-context worker state.
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
     * [PINS THE INVARIANT] No context ⇒ no rows.
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

<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsureTenantUser;
use App\Models\Invoice;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-07 — the mobile invoice-payment idempotency cache.
 *
 * WHAT DREW ATTENTION HERE
 * ------------------------
 * `Api\Mobile\InvoiceController::storePayment` reads a cached response at :171
 * and writes one at :289 under the key
 *
 *     invoice_payment_idempotency:{$invoice->id}:{$idempotencyKey}
 *
 * Neither `shop_id` nor `user_id` appears in that key, and the `Cache::get`
 * happens BEFORE `$shopId = $request->user()->shop_id` is read at :176. So on a
 * cache hit the controller returns a stored body having performed no shop check
 * of its own. Two files over, `PosController` keys the same kind of cache as
 * `pos_sell_idempotency:{$shopId}:{$userId}:{$key}`, and `EnsureIdempotency`'s
 * own docblock (:31) says "Scoped to (shop_id, user_id, key). Two users can use
 * the same key safely." The legacy path is the odd one out.
 *
 * THE ANSWER, AND WHY IT IS NOT "THE CACHE KEY IS FINE"
 * ----------------------------------------------------
 * Cross-tenant access is blocked — but NOT by anything in this controller. It is
 * blocked by route-model binding resolving `Invoice` through the `BelongsToShop`
 * global scope, so shop B's request 404s before `storePayment` is ever entered.
 * That distinction is the whole point of this file. If someone later switches
 * this route to an unscoped lookup (`withoutTenant`, a manual `find()`, a UUID
 * column, binding by a non-scoped key), the cache key offers NO second line of
 * defence and the leak is immediate. I-05 is written to fail in that world.
 *
 * MUTATION EVIDENCE, AND THE FINDING IT PRODUCED
 * ----------------------------------------------
 * These tests passed the first time they ran, so on their own they prove less
 * than a test watched failing. Two mutations were run to give them teeth:
 *
 *   Mutation                                          Killed    Survived
 *   A. Invoice::resolveRouteBinding() made unscoped    I-05      I-01 I-02
 *      (withoutTenant) -- "someone swaps in an                   I-03 I-04
 *      unscoped lookup", the scenario named above
 *   B. `can:sales.create` removed from the route       I-03      the rest
 *
 * I-02 SURVIVING MUTATION A IS NOT A WEAK TEST — it is the finding.
 * With the binding unscoped, shop B does reach the controller, and is then
 * stopped by a SECOND guard: the `Invoice::query()->lockForUpdate()
 * ->firstOrFail()` at :181 still carries the tenant scope, so the write path
 * 404s anyway. Two independent guards protect the write path.
 *
 * The cache-hit path had only ONE. `Cache::get` returns before that lock is
 * ever reached, so under mutation A shop B received HTTP 200 carrying shop A's
 * payment totals. I-05 was the only test that caught it.
 *
 * HOW THAT EVIDENCE IS CLASSIFIED — precisely, because the earlier revision of
 * this docblock was loose about it. The unchanged route binding blocks the
 * cross-shop request that I-05 sends; no exploit has been demonstrated against
 * unchanged code. What the mutation demonstrates is a DEPENDENCY on that single
 * guard, not a live exploit. That is why the repair below is defence in depth
 * rather than an incident fix.
 *
 * THE REPAIR, AND WHY IT IS NOT A CHANGE TO THE KEY
 * ------------------------------------------------
 * Adding `{$shopId}:{$userId}` to the key is a one-line change and is
 * deliberately NOT made. Changing the key shape means every in-flight
 * idempotency key misses the cache across the deploy window, and a client
 * retrying a partial payment in that window would have it recorded TWICE. The
 * overpayment guard at :194 only catches the duplicate when it pushes the total
 * past `outstanding` — pay 3,000 twice against a 10,000 invoice and both land,
 * silently. The key format, its 24h TTL and successful replay behaviour are
 * therefore all preserved, and no cached record is flushed.
 *
 * What is added instead is the smallest missing check BEFORE cached data can be
 * returned — `authorizeCachedPaymentReplay()` in the controller. Three
 * conditions, in order:
 *
 *   1. a trusted active tenant must exist (`TenantContext`); a request that
 *      reaches this line with no tenant context FAILS CLOSED rather than
 *      falling back to the user's own column,
 *   2. the invoice must belong to that tenant — 404, the same answer the
 *      scoped binding gives, so the guard does not turn into an existence
 *      oracle,
 *   3. the caller must hold `sales.create`, the same permission the route's
 *      `can:` middleware requires for fresh processing.
 *
 * Authorization now covers the cached response as well as the fresh write.
 * I-06..I-09 below pin all four halves of that.
 *
 * EVIDENCE FOR THE REPAIR, MEASURED
 * --------------------------------
 * I-07, I-08 and I-09 were watched failing BEFORE the guard existed, and each
 * failed the same way: HTTP 200 carrying shop A's `7777`. Not a 404, not a
 * routing error — the cached receipt handed to the wrong caller. I-06 passed
 * from the start, which is correct: it characterizes the replay behaviour the
 * repair had to leave alone, and it is the test that would fail if the guard
 * were written to refuse an authorized retry.
 *
 * One further mutation answered an attribution question the RED run could not:
 * with three conditions in the guard, WHICH one does I-09 depend on? Deleting
 * only `abort_if($tenantShopId === null, ...)` killed I-09 and nothing else —
 * so the fail-closed branch is load-bearing and separately pinned, rather than
 * being incidentally covered by the shop comparison below it. Restoration was
 * confirmed by md5sum and an empty `diff`, not by searching for the word
 * MUTATION.
 *
 * A CONSOLE-SPECIFIC TEST ADJUSTMENT, DECLARED RATHER THAN BURIED
 * ---------------------------------------------------------------
 * `actAs()` below calls `TenantContext::set()` as well as `Sanctum::actingAs()`.
 * That is not belt-and-braces; without it every test here 404s — INCLUDING the
 * positive control — and the file would "prove" isolation while actually
 * proving that nothing works at all. I watched exactly that happen: I-02 and
 * I-04 passed on the first run while I-01 failed, which is the signature of a
 * suite asserting denial against a route nobody can reach.
 *
 * The cause is `BelongsToShop::resolveTenantShopId()`: it reads `TenantContext`,
 * and failing that returns null when `app()->runningInConsole()` — TRUE under
 * PHPUnit — so the global scope takes its fail-closed `whereRaw('1 = 0')`
 * branch. Route-model binding runs before the `tenant` middleware has set any
 * context, so the binding query matches nothing even when `shop_id` is a
 * perfect match (verified: invoice.shop_id=1, owner.shop_id=1, context NULL).
 *
 * In production that binding IS scoped, through the `Auth::check()` branch that
 * console mode skips. Setting the context to the ACTING user's shop reproduces
 * the production scope faithfully.
 *
 * What it must NOT do — and this is the trap — is set the context to the
 * *target* shop while authenticating as someone else. That emulates a
 * middleware which has already lost the argument, and would invert I-02 into a
 * demonstration that shop B can reach shop A's invoice. The context here always
 * follows the principal, exactly as `EnsureTenantUser` makes it.
 *
 * SEVERITY, STATED PLAINLY: LOW, and not an exposure. Cross-shop is blocked.
 * Same-shop cross-user collision needs an attacker to guess a colleague's
 * random UUID (`newIdempotencyKey()` in the mobile repo uses
 * `crypto.randomUUID()`, falling back to millisecond + ~41 random bits). What
 * is real is the asymmetry and the fact that the protection lives somewhere
 * else entirely.
 */
class InvoicePaymentIdempotencyScopeTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    private const ROUTE = '/api/mobile/invoices/%d/payments';

    // -------------------------------------------------------------------- I-01
    /** Positive control. Without it the 404s below prove only that nothing works. */
    public function test_i01_an_authorized_operator_can_record_a_payment(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        $this->actAs($owner);

        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => 'key-owner-a-0001'])
            ->assertCreated()
            // int, not 3000.0: json_encode drops the trailing .0 from a whole
            // float and assertJsonPath compares identically.
            ->assertJsonPath('totals.paid_amount', 3000);
    }

    // -------------------------------------------------------------------- I-02
    /** Another shop's owner, fully authorized in their OWN shop. */
    public function test_i02_another_shops_owner_cannot_reach_this_invoice(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [$ownerB] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shopA->id, 10000.00);

        $this->actAs($ownerB);

        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => 'key-owner-b-0001'])->assertNotFound();

        $this->assertSame(0, $this->paymentCount($invoice->id));
    }

    // -------------------------------------------------------------------- I-03
    /** Same shop, same invoice, staff without `sales.create`. */
    public function test_i03_same_shop_staff_without_the_permission_is_refused(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        $staff = $this->staffWithout($owner, $shop->id);
        $this->actAs($staff);

        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => 'key-staff-0001'])->assertForbidden();

        $this->assertSame(0, $this->paymentCount($invoice->id));
    }

    // -------------------------------------------------------------------- I-04
    public function test_i04_a_guest_is_refused(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        // No principal, so no context either. A guest that reached the binding
        // with a context set would be a different test than the one named.
        TenantContext::clear();

        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => 'key-guest-0001'])->assertUnauthorized();

        $this->assertSame(0, $this->paymentCount($invoice->id));
    }

    // -------------------------------------------------------------------- I-05
    /**
     * THE ONE THAT MATTERS.
     *
     * Shop A records a payment, which populates
     * `invoice_payment_idempotency:{A's invoice id}:{key}`. Shop B then replays
     * the SAME key against the SAME invoice id — the exact request that would
     * hit that cache entry if the controller's own logic were the guard.
     *
     * The assertion is not merely "404". It is that shop A's figures do not
     * appear in shop B's response body. A 404 with A's totals in it would be an
     * odd bug; a 200 with A's totals is the leak this key shape permits the
     * moment the binding stops being scoped.
     */
    public function test_i05_another_shop_replaying_the_same_key_gets_no_cached_body(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shopA->id, 10000.00);

        $sharedKey = 'collision-key-0001';

        $this->actAs($ownerA);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 7777.00,
        ], ['X-Idempotency-Key' => $sharedKey])->assertCreated();

        // The cache entry really exists — otherwise I-05 would pass for the
        // uninteresting reason that there was nothing to leak.
        $this->assertNotNull(
            Cache::get("invoice_payment_idempotency:{$invoice->id}:{$sharedKey}"),
            'precondition: shop A\'s response must actually be cached'
        );

        $this->actAs($ownerB);
        $response = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 1.00,
        ], ['X-Idempotency-Key' => $sharedKey]);

        $response->assertNotFound();
        $response->assertDontSee('7777');
        $response->assertDontSee($invoice->invoice_number);
    }

    // -------------------------------------------------------------------- I-06
    /**
     * THE REPLAY POSITIVE CONTROL, and the one that stops the new guard being
     * an availability bug.
     *
     * A pre-existing cached receipt must stay usable by the authorized caller
     * who created it — same body back, and NO second payment row. If the guard
     * below were written slightly wrong (fail closed on a *present* tenant,
     * say) this is the test that would catch it, and it would catch it as a
     * double charge or a broken retry rather than as a security finding.
     */
    public function test_i06_an_authorized_caller_replays_a_cached_receipt_without_paying_twice(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        $this->actAs($owner);

        $key = 'replay-key-0001';
        $first = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(1, $this->paymentCount($invoice->id));

        // Re-arm the context for the SECOND request. `EnsureTenantUser` clears
        // it in its own finally at the end of request one, and under PHPUnit
        // nothing re-sets it (see the console note in the class docblock), so
        // without this line the replay 404s at the binding and the test would
        // "pass" its no-double-charge assertion for the wrong reason. Same
        // principal, same shop — this is not weakening anything.
        $this->actAs($owner);

        $replay = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertOk();

        // Same receipt, not a fresh one: identical body AND no new row.
        $this->assertSame($first->json('totals'), $replay->json('totals'));
        $this->assertSame(
            1,
            $this->paymentCount($invoice->id),
            'a replayed idempotency key must not record a second payment'
        );
    }

    // -------------------------------------------------------------------- I-07
    /**
     * The repair itself, under the condition that made S3-07 worth writing up.
     *
     * `unscopeRouteBinding()` is mutation A made permanent and automated: it
     * simulates the future in which someone swaps the scoped binding for a
     * plain lookup. Before the repair, shop B got HTTP 200 carrying shop A's
     * totals here. After it, the controller refuses on its own.
     *
     * Normal binding is left intact in every other test in this file; this one
     * weakens it ON PURPOSE and says so, because a guard that is only ever
     * exercised behind another guard is a guard nobody has tested.
     */
    public function test_i07_with_binding_weakened_another_shop_still_gets_no_cached_body(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shopA->id, 10000.00);

        $sharedKey = 'depth-key-0001';

        $this->actAs($ownerA);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 7777.00,
        ], ['X-Idempotency-Key' => $sharedKey])->assertCreated();

        $this->assertNotNull(
            Cache::get("invoice_payment_idempotency:{$invoice->id}:{$sharedKey}"),
            'precondition: shop A\'s response must actually be cached'
        );

        $this->unscopeRouteBinding();

        $this->actAs($ownerB);
        $response = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 1.00,
        ], ['X-Idempotency-Key' => $sharedKey]);

        $response->assertNotFound();
        $response->assertDontSee('7777');
        $response->assertDontSee($invoice->invoice_number);

        // No body AND no write — shop A's ledger is untouched by B's attempt.
        $this->assertSame(1, $this->paymentCount($invoice->id));
    }

    // -------------------------------------------------------------------- I-08
    /**
     * Same shop, cached entry present, caller lacks `sales.create`.
     *
     * `withoutMiddleware(Authorize::class)` is mutation B made automated: the
     * route's own `can:sales.create` is removed so the answer is attributable
     * to the CONTROLLER rather than to middleware configuration. The directive
     * asks for exactly that distinction — a surviving mutation can mean another
     * legitimate guard is still doing the work, and here I want to know whether
     * this one does any.
     */
    public function test_i08_same_shop_staff_without_the_permission_gets_no_cached_body(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        $key = 'perm-key-0001';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 7777.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        $staff = $this->staffWithout($owner, $shop->id);
        $this->actAs($staff);

        // Route middleware out of the way; the controller answers alone.
        $this->withoutMiddleware(Authorize::class);

        $response = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 1.00,
        ], ['X-Idempotency-Key' => $key]);

        $response->assertForbidden();
        $response->assertDontSee('7777');
        $this->assertSame(1, $this->paymentCount($invoice->id));
    }

    // -------------------------------------------------------------------- I-09
    /**
     * Fail closed on a missing tenant.
     *
     * If the `tenant` middleware is ever skipped, reordered, or a future caller
     * reaches this action out of band, the cache read must NOT fall back to
     * `$request->user()->shop_id` and serve the body anyway. Absent context is
     * a refusal, not a default.
     *
     * A CORRECTION, recorded rather than quietly patched: this test first tried
     * to produce "no context" by calling `TenantContext::clear()` before the
     * request, and it failed — 200, cached body served. The premise was wrong,
     * not the guard. `EnsureTenantUser:28` SETS the context from the
     * authenticated user on every request, so clearing beforehand is undone
     * microseconds later. The only honest way to test the fail-closed branch is
     * to remove the middleware that supplies the context, which is also exactly
     * the scenario the branch exists for.
     */
    public function test_i09_a_missing_tenant_context_refuses_the_cached_body(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        $key = 'noctx-key-0001';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 7777.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        // Binding weakened, because with a scoped binding and no context the
        // 404 would come from the binding and prove nothing about the guard.
        $this->unscopeRouteBinding();

        Sanctum::actingAs($owner);
        $this->withoutMiddleware(EnsureTenantUser::class);
        TenantContext::clear();

        $response = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 1.00,
        ], ['X-Idempotency-Key' => $key]);

        $response->assertForbidden();
        $response->assertDontSee('7777');
        $this->assertSame(1, $this->paymentCount($invoice->id));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Replace the scoped implicit binding with an unscoped lookup, simulating
     * the regression named in the class docblock. An explicit `Route::bind`
     * wins over implicit model binding, so the rest of the stack — auth,
     * tenant, throttle, the controller — stays exactly as it ships.
     */
    private function unscopeRouteBinding(): void
    {
        Route::bind('invoice', fn ($value) => Invoice::withoutGlobalScope('shop')->findOrFail($value));
    }

    /**
     * Authenticate, and put the tenant context where the `tenant` middleware
     * would put it for THIS principal. See the class docblock: without the
     * second line every test in this file 404s, positive control included.
     */
    private function actAs(User $user): void
    {
        Sanctum::actingAs($user);
        TenantContext::set((int) $user->shop_id);
    }

    private function finalizedInvoice(int $shopId, float $total): Invoice
    {
        $customer = $this->createCustomer($shopId);

        $invoice = new Invoice();
        // Article I: Invoice money columns are GUARDED, so fixtures seed them
        // with forceFill rather than by mass assignment.
        $invoice->forceFill([
            'shop_id' => $shopId,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-IDEM-'.$shopId.'-'.uniqid(),
            'status' => Invoice::STATUS_FINALIZED,
            // NOT NULL with no default, per information_schema on
            // jewelflow_testing. Not a business input for this test.
            'gold_rate' => 6000,
            'subtotal' => $total,
            'total' => $total,
            'gst' => 0,
            'gst_rate' => 0,
            'finalized_at' => now(),
        ])->save();

        return $invoice;
    }

    private function staffWithout(User $owner, int $shopId): User
    {
        $staff = User::factory()->create([
            'shop_id' => $shopId,
            'role_id' => $owner->role_id,
        ]);

        // Everything except sales.create, so the refusal is attributable to the
        // one missing permission rather than to a user with no permissions.
        $this->grantOnlyPermissions($staff, ['sales.view']);

        return $staff;
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) \DB::table('invoice_payments')->where('invoice_id', $invoiceId)->count();
    }
}

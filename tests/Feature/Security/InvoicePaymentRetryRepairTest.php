<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-07b REPAIR — durable coordination of the payment commit and its replay record.
 *
 * THESE ARE DESIRED-BEHAVIOUR TESTS, WRITTEN AND RUN RED FIRST.
 * -------------------------------------------------------------
 * The companion file `InvoicePaymentRetryIntegrityTest` characterizes what the
 * code did BEFORE this repair and says so. This file states what it must do
 * after. Every test here was run against unrepaired code first and the RED
 * output is recorded in the handoff §8; none of them passed on first run.
 *
 * WHAT WAS ACTUALLY WRONG
 * -----------------------
 * `storePayment` did, in order:
 *
 *     1. Cache::get(legacy key)            -- hit? replay
 *     2. DB::transaction { ... COMMIT }    -- durable
 *     3. Cache::put(legacy key, 24h)       -- separate store, best effort
 *
 * Steps 2 and 3 are not coordinated. Anything that lands 2 without 3 — a crash,
 * an eviction, a Redis blip, or a second concurrent first request that read its
 * miss before either reached step 3 — leaves a committed payment with no replay
 * record, and the next retry of that key is indistinguishable from a first
 * request. R-01 in the companion file demonstrates the double charge.
 *
 * WHY `EnsureIdempotency` WAS NOT SIMPLY REUSED
 * ---------------------------------------------
 * It was inspected rather than assumed, and it does not fit — for two reasons,
 * either of which alone is disqualifying.
 *
 *   1. ITS TRANSACTION BOUNDARIES HAVE THE SAME DEFECT. The middleware runs
 *      `$next($request)` (line 140) and only then calls
 *      `IdempotencyKey::create()` (line 149), in a separate statement outside
 *      whatever transaction the controller used. Its own comment concedes the
 *      consequence: on a write failure it fails soft, and "a future retry with
 *      the same key will simply re-run (not ideal...)". For a payment route
 *      "simply re-runs" IS the double charge. Adopting the middleware unchanged
 *      would have looked like a repair while leaving R-01 exactly as it was.
 *
 *   2. ITS IDENTITY IS WIDER THAN THE LEGACY KEY'S. `idempotency_keys` is
 *      UNIQUE on (shop_id, user_id, key). The legacy cache key is
 *      (invoice_id, key) — no user in it. Adopting the table would make two
 *      users in one shop, using one key against one invoice, claim two separate
 *      rows and charge twice: a REGRESSION introduced by the "fix". And
 *      `user_id` is nullable, so collapsing it to NULL to recover the narrower
 *      identity does not work either — PostgreSQL treats NULLs as distinct in a
 *      UNIQUE index, which would remove the uniqueness altogether.
 *
 * So the repair uses a dedicated record keyed exactly the way the legacy key is
 * keyed, (invoice_id, key), written INSIDE the payment transaction.
 *
 * THE THREE MECHANISMS BELOW ARE KEPT APART ON PURPOSE
 * ----------------------------------------------------
 * The directive asks that observed concurrency, injected failure and simulated
 * cache loss not be blurred together, because they carry different evidential
 * weight. They are labelled per test:
 *
 *   [SIMULATED CACHE LOSS]  `Cache::forget`/`flush` stands in for eviction,
 *                           restart or an unreachable store. Real mechanism,
 *                           simulated trigger.
 *   [INJECTED FAILURE]      the request is made to fail at a chosen point.
 *                           Real failure, chosen location.
 *   [OBSERVED CONSTRAINT]   an actual database constraint is exercised and its
 *                           actual behaviour recorded.
 *   [NOT RUN]               stated as such, with the reason, never inferred.
 *
 * WHAT IS NOT DEMONSTRATED HERE, STATED PLAINLY
 * ---------------------------------------------
 * A genuine two-process HTTP race is NOT RUN. Under PHPUnit with
 * `RefreshDatabase` every test body runs inside one uncommitted transaction, so
 * a second connection cannot see the fixtures this test created and a second OS
 * process cannot reach them at all. P-08 exercises the constraint that provides
 * the mutual exclusion, and P-09 exercises the losing path, but neither is a
 * scheduler observation and neither is described as one.
 */
class InvoicePaymentRetryRepairTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    private const ROUTE = '/api/mobile/invoices/%d/payments';

    private const LEGACY_KEY = 'invoice_payment_idempotency:%d:%s';

    // ==================================================================== P-01
    /**
     * [SIMULATED CACHE LOSS] The defect itself: a processed key whose cache
     * record is gone must NOT be reprocessed.
     *
     * This is R-01 inverted. The `Cache::flush()` stands in for every way the
     * volatile record can vanish after the durable commit. The durable claim
     * written inside the payment transaction is what must answer instead.
     *
     * Asserts financial effect, not just the response: one payment row, 3,000
     * collected, and — because a duplicate would also duplicate the money
     * trail — one cash transaction and one audit log.
     */
    public function test_p01_a_processed_key_survives_total_cache_loss_without_charging_again(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'repair-key-0001';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(1, $this->paymentCount($invoice->id));

        // Every volatile trace of the request is destroyed.
        Cache::flush();

        $this->actAs($owner);
        $replay = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key]);

        $replay->assertSuccessful();

        $this->assertSame(1, $this->paymentCount($invoice->id),
            'REPAIR: the durable claim must answer the retry, not a second INSERT');
        $this->assertSame(3000.0, $this->paidTotal($invoice->id));

        // Ledger effects, not only the payments table.
        $this->assertSame(1, $this->cashTransactionCount($invoice->id),
            'a duplicate payment would have duplicated the cash trail too');
        $this->assertSame(1, $this->auditCount($invoice->id),
            'and the audit trail');
    }

    // ==================================================================== P-02
    /**
     * Authorized same-key replay returns the ORIGINAL receipt, and says it is a
     * replay rather than pretending to be a fresh collection.
     */
    public function test_p02_replay_returns_the_original_receipt_and_is_marked_as_a_replay(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'repair-key-0002';

        $this->actAs($owner);
        $first = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        $firstPaymentId = $first->json('payment.id');

        Cache::flush();

        $this->actAs($owner);
        $replay = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key]);

        $this->assertSame($firstPaymentId, $replay->json('payment.id'),
            'the replay must describe the payment that actually happened');
        $this->assertSame('true', $replay->headers->get('X-Idempotent-Replay'),
            'and must be identifiable as a replay by the client');
        $this->assertSame(1, $this->paymentCount($invoice->id));
    }

    // ==================================================================== P-03
    /**
     * Changed parameters under the same key → 409, and NOTHING is recorded.
     *
     * This is R-02 inverted and needs no race, no crash and no eviction. Today
     * the operator is shown a 200 with the FIRST receipt while the amount they
     * just keyed in is silently dropped.
     */
    public function test_p03_the_same_key_with_a_different_amount_is_refused_as_a_conflict(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'repair-key-0003';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        $this->actAs($owner);
        $conflict = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 4500.00,
        ], ['X-Idempotency-Key' => $key]);

        $conflict->assertStatus(409);

        $this->assertSame(1, $this->paymentCount($invoice->id),
            'the 4,500 must not be recorded');
        $this->assertSame(3000.0, $this->paidTotal($invoice->id),
            'and the original must not be disturbed');
    }

    // ==================================================================== P-04
    /**
     * The control that keeps every other test honest: two DISTINCT keys are two
     * genuine collections and both must land.
     *
     * Without this, an implementation that simply refused every second payment
     * against an invoice would pass P-01 and P-03 while destroying part-payment
     * collection, which is a normal supported flow on this endpoint.
     */
    public function test_p04_two_distinct_keys_still_record_two_distinct_payments(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => 'repair-distinct-0001'])->assertCreated();

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 4500.00,
        ], ['X-Idempotency-Key' => 'repair-distinct-0002'])->assertCreated();

        $this->assertSame(2, $this->paymentCount($invoice->id));
        $this->assertSame(7500.0, $this->paidTotal($invoice->id));
        $this->assertSame(2, $this->cashTransactionCount($invoice->id));
    }

    // ==================================================================== P-05
    /**
     * [INJECTED FAILURE — before commit] A request that fails inside the
     * transaction must leave NOTHING behind, including no claim on the key.
     *
     * The failure is injected by overpaying: the outstanding-balance guard
     * throws inside `DB::transaction`, so the whole thing rolls back. The point
     * is the SECOND half — the same key must still be usable afterwards. An
     * implementation that claimed the key before or outside the transaction
     * would burn it here and permanently refuse a payment that never happened.
     */
    public function test_p05_a_failure_before_commit_leaves_no_payment_and_does_not_burn_the_key(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 5000.00);
        $key = 'repair-key-0005';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 9000.00,
        ], ['X-Idempotency-Key' => $key])->assertStatus(422);

        $this->assertSame(0, $this->paymentCount($invoice->id));
        $this->assertSame(0, $this->claimCount($invoice->id),
            'a failed attempt must not leave a durable claim behind');

        // The same key must still work — it never succeeded at anything.
        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 2000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(1, $this->paymentCount($invoice->id));
        $this->assertSame(2000.0, $this->paidTotal($invoice->id));
    }

    // ==================================================================== P-06
    /**
     * [INJECTED FAILURE — after payment commit, before response/cache storage]
     *
     * The precise window R-01 lives in. The payment is committed; the process
     * then dies before the volatile record is written and before the client
     * ever sees a response. The client, having received nothing, retries.
     *
     * Modelled by committing normally and then destroying the cache entry
     * WITHOUT touching the durable claim — which is exactly the state that
     * window leaves behind once the claim is inside the transaction.
     */
    public function test_p06_a_crash_after_commit_but_before_the_cache_write_does_not_double_charge(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'repair-key-0006';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 2500.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        // The volatile half of step 3 never durably happened.
        Cache::forget(sprintf(self::LEGACY_KEY, $invoice->id, $key));

        $this->assertSame(1, $this->claimCount($invoice->id),
            'the durable claim must have committed WITH the payment');

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 2500.00,
        ], ['X-Idempotency-Key' => $key])->assertSuccessful();

        $this->assertSame(1, $this->paymentCount($invoice->id));
        $this->assertSame(2500.0, $this->paidTotal($invoice->id));
        $this->assertSame(1, $this->cashTransactionCount($invoice->id));
    }

    // ==================================================================== P-07
    /**
     * A foreign-shop caller gets nothing from the durable replay path.
     *
     * The access-control question is covered for the CACHE path in
     * `InvoicePaymentIdempotencyScopeTest`. The durable record is a NEW place a
     * payment receipt is stored, so it needs its own answer rather than
     * inheriting one.
     */
    public function test_p07_a_foreign_shop_caller_cannot_replay_someone_elses_claim(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shopA->id, 10000.00);
        $key = 'repair-key-0007';

        $this->actAs($ownerA);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        [$ownerB] = $this->createRetailerTenant();

        $this->actAs($ownerB);
        $foreign = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key]);

        $this->assertContains($foreign->getStatusCode(), [403, 404],
            'shop B must be refused, by binding or by guard');

        $body = $foreign->getContent();
        $this->assertStringNotContainsString('3000', $body,
            'and must not be shown the receipt amount');
        $this->assertStringNotContainsString((string) $invoice->invoice_number, $body,
            'nor the invoice number');

        $this->assertSame(1, $this->paymentCount($invoice->id),
            'and no business side effect may occur on the refused path');
    }

    // ==================================================================== P-08
    /**
     * [OBSERVED CONSTRAINT] The database constraint that provides the mutual
     * exclusion actually exists and actually rejects a duplicate.
     *
     * This is the mechanism a genuine two-process race relies on: the loser of
     * the race collides on this unique index rather than proceeding to INSERT a
     * second payment. Exercising the constraint directly is a real observation;
     * it is NOT a scheduler observation and is not offered as one.
     */
    public function test_p08_the_claim_identity_is_unique_per_invoice_and_key(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'repair-key-0008';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        // MUST be the unique-violation subclass, NOT the QueryException parent.
        //
        // The first draft of this test expected QueryException and PASSED on the
        // RED run — before the table existed — because "relation does not exist"
        // is also a QueryException. The assertion was being satisfied by the
        // absence of the very thing it exists to verify. Narrowing it to
        // UniqueConstraintViolationException makes the table's existence a
        // precondition of passing rather than an alternative route to it.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        // A second worker attempting to claim the same (invoice, key).
        DB::table('invoice_payment_claims')->insert([
            'invoice_id'      => $invoice->id,
            'shop_id'         => $shop->id,
            'user_id'         => $owner->id,
            'key'             => $key,
            'request_hash'    => str_repeat('a', 64),
            'response_status' => 201,
            'response_body'   => json_encode(['payment' => null]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ==================================================================== P-09
    /**
     * [OBSERVED CONSTRAINT] The losing side of a race replays instead of
     * charging.
     *
     * A claim committed by "another worker" is pre-inserted, then the request
     * arrives. With the repair, the lookup finds that claim and returns it. The
     * absence of a second payment row is the property under test.
     *
     * The uniqueness identity is deliberately (invoice_id, key) — matching the
     * legacy cache key exactly — so a second USER in the same shop retrying the
     * same key against the same invoice is also deduplicated. Keying on the
     * user, as `idempotency_keys` does, would have charged twice here.
     */
    public function test_p09_a_claim_committed_by_another_worker_is_replayed_not_reprocessed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'repair-key-0009';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        Cache::flush();

        // A DIFFERENT user in the same shop retries the same key.
        $second = $this->createStaffUser($shop->id);

        $this->actAs($second);
        $replay = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key]);

        $replay->assertSuccessful();
        $this->assertSame(1, $this->paymentCount($invoice->id),
            'identity is (invoice, key) — a second user must not re-charge it');
        $this->assertSame(3000.0, $this->paidTotal($invoice->id));
    }

    // ==================================================================== P-10
    /**
     * Missing tenant context fails CLOSED on the durable replay path.
     *
     * Note the asymmetry this test pins, because it is the subtle part of the
     * repair: for an AUTHORIZATION question, failing closed means "refuse". For
     * a DEDUPLICATION question, failing closed cannot mean "treat it as a first
     * request" — that is the double charge. So an unresolvable context must
     * refuse the whole request, never fall through to processing.
     */
    public function test_p10_missing_tenant_context_refuses_rather_than_reprocessing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'repair-key-0010';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        Cache::flush();

        Sanctum::actingAs($owner);
        TenantContext::clear();

        $response = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key]);

        // A DELIBERATE REFUSAL, not a crash. `>= 400` alone would also be
        // satisfied by a 500, and "it threw an unhandled exception" is not the
        // same claim as "it declined". The distinction matters here because a
        // crash on this path would still be a bug even though it happens not to
        // double-charge.
        $this->assertContains($response->getStatusCode(), [403, 404, 422, 503],
            'no tenant context must be REFUSED deliberately, not 500');
        $this->assertSame(1, $this->paymentCount($invoice->id),
            'and above all must not reprocess');
    }

    // ==================================================================== P-11
    /**
     * Legacy cache entries still replay, and the compatibility limit is pinned.
     *
     * A key written by the OLD code path has a cached body but no durable claim
     * and — the part that matters — no stored request hash. There is therefore
     * no original payload identity to compare a retry against.
     *
     * This test asserts what IS true: such an entry still replays, so keys in
     * flight across the deploy are not orphaned into a double charge. It does
     * NOT assert a 409 for a changed payload on a legacy entry, because the
     * evidence required to detect that was never recorded and cannot be
     * reconstructed. Inventing a hash from the replayed body would fabricate
     * exactly the evidence that is missing. The gap closes on its own when the
     * 24-hour TTL expires the last legacy entry.
     */
    public function test_p11_a_legacy_cache_entry_still_replays_and_does_not_double_charge(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'legacy-key-0011';

        // Exactly what the pre-repair code left behind: body in cache, no claim.
        Cache::put(sprintf(self::LEGACY_KEY, $invoice->id, $key), [
            'payment'  => ['id' => 999, 'mode' => 'cash', 'amount' => 3000.0],
            'payments' => [['id' => 999, 'mode' => 'cash', 'amount' => 3000.0]],
            'totals'   => ['total' => 10000.0, 'paid_amount' => 3000.0, 'outstanding_amount' => 7000.0],
        ], now()->addHours(24));

        $this->assertSame(0, $this->claimCount($invoice->id));

        $this->actAs($owner);
        $replay = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key]);

        $replay->assertSuccessful();
        $this->assertSame(999, $replay->json('payment.id'),
            'the legacy body must still be served');
        $this->assertSame(0, $this->paymentCount($invoice->id),
            'and must not be reprocessed into a real second payment');
    }

    // ==================================================================== P-12
    /**
     * [OBSERVED] An INCONSISTENT claim is refused, and the refusal does not
     * fall through into taking the payment again.
     *
     * WHY THIS TEST EXISTS, STATED HONESTLY. `InvoicePaymentClaim`'s docblock
     * asserted that "`shop_id` is stored and asserted by the controller before
     * any claim body is returned". Re-reading the controller for this review,
     * **the second half of that sentence was not true** — nothing read
     * `$claim->shop_id`. The comment described a control that did not exist.
     *
     * This is NOT a demonstrated cross-tenant exploit and is not reported as
     * one. `EnsureTenantUser` sets the context from `auth()->user()->shop_id`,
     * the invoice arrives through a tenant-scoped binding, and the claim is
     * written with that same shop — so all three agree on every path the
     * shipped code can take, and a foreign caller 404s at the binding before
     * reaching here (P-07).
     *
     * What it defends is the row itself being wrong: a corrupted write, a
     * restore, a future second writer, or a migration that fills the column
     * from a different source. In that state the pre-P-12 code would have
     * replayed a body belonging to another shop. The claim is authorization-
     * bearing data, so it gets checked rather than trusted.
     *
     * The required answer is a REFUSAL, not a fall-through: "this claim is not
     * usable" must never resolve to "so take the payment again", which is the
     * whole failure mode this finding family is about.
     */
    public function test_p12_a_claim_whose_shop_does_not_match_is_refused_without_reprocessing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'inconsistent-key-0012';

        $body = ['payment' => ['id' => 4242, 'mode' => 'cash', 'amount' => 3000.0]];
        $payload = ['mode' => 'cash', 'amount' => 3000.00];

        // THE HASH MUST MATCH, AND THE FIRST DRAFT OF THIS TEST GOT IT WRONG.
        //
        // That draft stored a hash of `{}`. The test PASSED on the RED run —
        // but it passed because the payload hashes disagreed, so the controller
        // answered 409 `idempotency_key_conflict`. A 409 for the wrong reason
        // satisfied an assertion that only looked at the status code, exactly
        // as P-08's first draft was satisfied by "relation does not exist".
        //
        // Reproducing the controller's own hash makes the payload IDENTICAL, so
        // the only thing left wrong is `shop_id`. `TestCase::json()` encodes
        // with `json_encode($data, 0)` (verified in
        // Foundation/Testing/Concerns/MakesHttpRequests.php:565), so plain
        // json_encode reproduces the raw body byte for byte.
        $requestHash = hash(
            'sha256',
            'POST|api/mobile/invoices/'.$invoice->id.'/payments|'.json_encode($payload, 0)
        );

        // A claim for the RIGHT invoice, key and payload — carrying the WRONG shop.
        DB::table('invoice_payment_claims')->insert([
            'invoice_id'      => $invoice->id,
            'shop_id'         => $otherShop->id,
            'user_id'         => null,
            'key'             => $key,
            'request_hash'    => $requestHash,
            'response_status' => 201,
            'response_body'   => json_encode($body),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->actAs($owner);
        $response = $this->postJson(sprintf(self::ROUTE, $invoice->id), $payload,
            ['X-Idempotency-Key' => $key]);

        // Refused. Not 2xx — a 2xx here would mean either the foreign body was
        // served or a fresh payment was taken under a key already claimed.
        $this->assertContains($response->status(), [409, 422, 503], sprintf(
            'an inconsistent claim must be refused, got %d', $response->status()
        ));

        // AND refused for the RIGHT reason. Without this the payload-conflict
        // path could satisfy the status assertion above all over again.
        $this->assertNotSame('idempotency_key_conflict', $response->json('errors.0.code'),
            'this must be refused as an inconsistent claim, not as a payload conflict');

        // The foreign body must not appear in the response at all.
        $this->assertStringNotContainsString('4242', $response->getContent(),
            'the other shop\'s stored receipt must not be echoed back');

        // AND the refusal must not have become a second charge.
        $this->assertSame(0, $this->paymentCount($invoice->id),
            'refusing the claim must not fall through into processing a payment');
        $this->assertSame(0.0, $this->paidTotal($invoice->id));
    }

    // ==================================================================== P-13
    /**
     * [OBSERVED] Staff who have LOST payment permission cannot replay a receipt
     * they were authorized to create earlier.
     *
     * The stale-authorization question: the claim was created while the user
     * held `sales.create`. If replay were answered from the stored record
     * without re-deciding permission, revocation would take effect for new
     * payments but not for replays — the receipt would keep being served to
     * someone no longer allowed to see it.
     *
     * The positive control is inside the same test: the SAME user, on the SAME
     * key, succeeds before the revocation and is refused after it. Without that
     * first call this would pass against a route that was simply broken.
     */
    public function test_p13_revoked_payment_permission_cannot_replay_an_earlier_receipt(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $staff = $this->createStaffUser($shop->id);
        $key = 'revoked-key-0013';

        // POSITIVE CONTROL — authorized, so the payment and its claim exist.
        $this->actAs($staff);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(1, $this->paymentCount($invoice->id));
        $this->assertSame(1, $this->claimCount($invoice->id));

        // Revoke `sales.create` from the role this user holds.
        $role = Role::withoutTenant()->findOrFail($staff->role_id);
        $role->revokePermission('sales.create');

        // Same user, same key, same payload — now unauthorized.
        $this->actAs($staff->fresh());
        $replay = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash', 'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key]);

        $replay->assertForbidden();

        // No receipt data may leak through the refusal.
        $this->assertStringNotContainsString('outstanding_amount', $replay->getContent(),
            'a refused replay must not carry the stored receipt body');

        // And the refusal must not disturb what was already recorded.
        $this->assertSame(1, $this->paymentCount($invoice->id));
        $this->assertSame(3000.0, $this->paidTotal($invoice->id));
    }

    // ==================================================================== P-14
    /**
     * [INJECTED FAILURE] A unique violation that is NOT the claim-key collision
     * must surface as an error, never be answered with someone else's receipt.
     *
     * WHY THIS BECAME REACHABLE. The claim INSERT was moved to the START of the
     * payment transaction (harness R-C5: at the end, a serialized loser was
     * rejected by "already fully paid" before ever reaching it, and was told its
     * own successful payment had failed). That fix widened the catch: any unique
     * violation raised AFTER the claim insert now lands in the same handler.
     *
     * And the window is real, not theoretical. Our uncommitted claim row holds
     * the index entry, so a competing worker's INSERT BLOCKS on it. If we then
     * fail for an unrelated reason, our rollback releases the entry, the
     * competitor commits, and by the time the handler looks up "the winner"
     * there IS one — so the pre-fix handler would return 200 and the unrelated
     * database error would vanish.
     *
     * The sequence is reproduced exactly, with real events and a real row:
     *   - an unrelated unique violation is raised once the payment row exists;
     *   - the competing claim is planted on the actual rollback, i.e. after our
     *     own claim row is gone, which is the only moment it could appear.
     *
     * The planted hash MATCHES the request. If it did not, the handler would
     * answer 409 and this test would pass without the guard ever running — the
     * same false pass P-12's first draft produced.
     */
    public function test_p14_an_unrelated_unique_violation_is_not_reported_as_a_replay(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);
        $key = 'repair-key-0014';
        $payload = ['mode' => 'cash', 'amount' => 3000.00];

        $requestHash = hash(
            'sha256',
            'POST|api/mobile/invoices/'.$invoice->id.'/payments|'.json_encode($payload, 0)
        );

        // Plant the competitor's claim at the moment our transaction rolls back.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Database\Events\TransactionRolledBack::class,
            function () use ($invoice, $shop, $key, $requestHash) {
                if (DB::table('invoice_payment_claims')->where('key', $key)->exists()) {
                    return;
                }
                DB::table('invoice_payment_claims')->insert([
                    'invoice_id'      => $invoice->id,
                    'shop_id'         => $shop->id,
                    'user_id'         => null,
                    'key'             => $key,
                    'request_hash'    => $requestHash,
                    'response_status' => 201,
                    'response_body'   => json_encode(['totals' => ['outstanding_amount' => 7000]]),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        );

        // An unrelated unique violation, raised after the payment row is written
        // and therefore after the claim row was staked.
        \App\Models\InvoicePayment::created(function () {
            throw new \Illuminate\Database\UniqueConstraintViolationException(
                'pgsql',
                'insert into "some_other_table" ...',
                [],
                new \RuntimeException(
                    'SQLSTATE[23505]: duplicate key value violates unique constraint "some_other_table_unique"'
                )
            );
        });

        $this->actAs($owner);
        $response = $this->postJson(sprintf(self::ROUTE, $invoice->id), $payload,
            ['X-Idempotency-Key' => $key]);

        $this->assertNotSame(200, $response->getStatusCode(),
            'an unrelated unique violation must not be answered as a successful replay');
        $this->assertStringNotContainsString('outstanding_amount', $response->getContent(),
            'no stored receipt body may be returned for an error we did not recognize');

        // And nothing was charged: the transaction that failed took its payment
        // row with it.
        $this->assertSame(0, $this->paymentCount($invoice->id));
    }

    // ------------------------------------------------------------------ helpers

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user);
        TenantContext::set((int) $user->shop_id);
    }

    private function createStaffUser(int $shopId): User
    {
        // `withoutTenant()` because `EnsureTenantUser` clears TenantContext in
        // its finally at the end of every request, so by the time this fixture
        // runs the context is null and BelongsToShop's fail-closed
        // `whereRaw('1 = 0')` returns no role at all. The scope is behaving
        // correctly; it is the FIXTURE that must opt out, never the scope that
        // gets relaxed to make a test convenient.
        $role = Role::withoutTenant()->where('shop_id', $shopId)->firstOrFail();

        // Built through the same factory `CreatesTestTenant::createOwnerUser`
        // uses. A hand-rolled row missed `is_active`, and the app's Gate::before
        // resolves dotted abilities via User::hasPermission() with no owner
        // short-circuit — so an inactive user with the full permission set is
        // still refused 403. Reusing the established fixture path avoids
        // rediscovering each NOT NULL column and each activation flag by
        // running into them one at a time.
        return User::factory()->create([
            'shop_id'   => $shopId,
            'role_id'   => $role->id,
            'is_active' => true,
        ]);
    }

    private function finalizedInvoice(int $shopId, float $total): Invoice
    {
        $customer = $this->createCustomer($shopId);

        $invoice = new Invoice();
        // Article I: Invoice money columns are GUARDED.
        $invoice->forceFill([
            'shop_id' => $shopId,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-REPAIR-'.$shopId.'-'.uniqid(),
            'status' => Invoice::STATUS_FINALIZED,
            'gold_rate' => 6000,
            'subtotal' => $total,
            'total' => $total,
            'gst' => 0,
            'gst_rate' => 0,
            'finalized_at' => now(),
        ])->save();

        return $invoice;
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) DB::table('invoice_payments')->where('invoice_id', $invoiceId)->count();
    }

    private function paidTotal(int $invoiceId): float
    {
        return (float) DB::table('invoice_payments')->where('invoice_id', $invoiceId)->sum('amount');
    }

    private function cashTransactionCount(int $invoiceId): int
    {
        return (int) DB::table('cash_transactions')->where('invoice_id', $invoiceId)->count();
    }

    private function auditCount(int $invoiceId): int
    {
        return (int) DB::table('audit_logs')
            ->where('model_type', 'invoice')
            ->where('model_id', $invoiceId)
            ->where('action', 'invoice_payment_recorded')
            ->count();
    }

    private function claimCount(int $invoiceId): int
    {
        return (int) DB::table('invoice_payment_claims')->where('invoice_id', $invoiceId)->count();
    }
}

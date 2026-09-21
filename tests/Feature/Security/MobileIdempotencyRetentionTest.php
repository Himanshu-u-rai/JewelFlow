<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\IdempotencyKey;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-09b — the retention contract for UNRESOLVED idempotency claims.
 *
 * S3-09's repair stakes the claim BEFORE the controller and refuses a same-key
 * retry while that claim sits in flight (response_status = 0). That protection
 * lasts exactly as long as the row does.
 *
 * `PruneIdempotencyKeys` deletes by age alone:
 *
 *     IdempotencyKey::where('created_at', '<', $cutoff)->delete();
 *
 * There is no filter on `response_status`, so an unresolved claim — one whose
 * business effect is UNKNOWN — is deleted on exactly the same schedule as a
 * cleanly completed one. Once it is gone the key is fresh again and the same
 * request re-runs the controller.
 *
 * THE AGE THRESHOLD IS NOT THE DELETION TIME. Two separate numbers, and the
 * handoff must not conflate them:
 *
 *   * `--hours=48` is the eligibility threshold.
 *   * `Schedule::command('mobile:prune-idempotency-keys')->daily()`
 *     (routes/console.php:147) runs at midnight Asia/Kolkata, so a row that
 *     becomes eligible at 00:30 is not considered again until the following
 *     midnight. Real deletion age is therefore anywhere from 48h to ~72h.
 *   * And all of that presupposes `schedule:run` is actually being invoked by
 *     cron on the box. That is NOT VERIFIED from here and is not verifiable
 *     from the test suite.
 *
 * So the pruner is NOT a financial-safety mechanism and must not be described
 * as one. It is a table-size mechanism whose current form happens to destroy a
 * financial-safety property.
 *
 * WHAT THIS FILE DOES AND DOES NOT CLAIM. It demonstrates, on synthetic data,
 * that deleting an unresolved claim re-permits the money operation. It does
 * NOT claim any real operation was ever lost this way, and it does not claim
 * the 48h window has ever actually elapsed in production with an unresolved
 * row present. No live pruning and no operational reconciliation was run.
 */
class MobileIdempotencyRetentionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function actAsOwner(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);

        return [$owner, $shop];
    }

    /**
     * See MobileIdempotencyRetryIntegrityTest::postAsTenant for why the
     * context must be re-established before EVERY request under PHPUnit.
     */
    private function postAsTenant(int $shopId, string $uri, array $payload, array $headers): \Illuminate\Testing\TestResponse
    {
        TenantContext::set($shopId);

        return $this->withHeaders($headers)->postJson($uri, $payload);
    }

    private function cashCount(int $shopId): int
    {
        return CashTransaction::withoutTenant()->where('shop_id', $shopId)->count();
    }

    private function cashPayload(): array
    {
        return [
            'type' => 'in',
            'amount' => 2500.00,
            'source_type' => 'other',
            'payment_mode' => 'cash',
            'description' => 'Counter sale float top-up',
        ];
    }

    /**
     * Drive a request into the "business effect committed, outcome unknown"
     * state and leave the claim unresolved.
     *
     * Bare `return;` is load-bearing: Eloquent dispatches model events through
     * `until()`, so any non-null return halts the chain and suppresses later
     * listeners — including BelongsToShop's shop_id hook.
     */
    private function armClaimCompletionFailure(): callable
    {
        $fired = false;

        IdempotencyKey::updating(function () use (&$fired) {
            if ($fired) {
                return;
            }
            $fired = true;
            throw new \RuntimeException('process died after the business commit, before recording the outcome');
        });

        return function () use (&$fired) {
            return $fired;
        };
    }

    /**
     * Put a real unresolved claim on a real money route, and return the shop.
     *
     * Deliberately NOT hand-inserted. A hand-built row would prove only that
     * the pruner deletes rows; going through the route proves the row the
     * pruner deletes is the one protecting a committed cash movement.
     */
    private function commitCashWithUnresolvedClaim(string $key): array
    {
        [, $shop] = $this->actAsOwner();
        $didFire = $this->armClaimCompletionFailure();

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), [
            'X-Idempotency-Key' => $key,
        ])->assertCreated();

        $this->assertTrue($didFire(), 'the injected completion failure never fired — no unresolved claim was produced');

        $claim = IdempotencyKey::where('shop_id', $shop->id)->where('key', $key)->sole();
        $this->assertSame(0, (int) $claim->response_status, 'expected the claim to be left unresolved');
        $this->assertSame(1, $this->cashCount((int) $shop->id), 'expected the cash movement to have committed');

        return [$shop, $claim];
    }

    // ────────────────────────────────────────────────────────────────────
    // The consequence
    // ────────────────────────────────────────────────────────────────────

    /**
     * An unresolved claim must not be deleted merely for being old.
     *
     * This is the assertion the repair depends on and does not currently have.
     * The money assertion is the second half: if the row goes, the same key
     * books a second cash movement.
     */
    public function test_pruning_must_not_delete_a_claim_whose_outcome_is_unknown(): void
    {
        $key = 'ret-unresolved-key';
        [$shop, $claim] = $this->commitCashWithUnresolvedClaim($key);

        // Age it past the retention threshold. created_at is the only column
        // the pruner looks at.
        IdempotencyKey::whereKey($claim->id)->update(['created_at' => now()->subHours(49)]);

        $this->artisan('mobile:prune-idempotency-keys')->assertSuccessful();

        $this->assertSame(
            1,
            IdempotencyKey::where('shop_id', $shop->id)->where('key', $key)->count(),
            'UNRESOLVED CLAIM PRUNED: the row protecting a committed cash movement was deleted by age alone',
        );
    }

    /**
     * The money consequence of the above, end to end.
     *
     * Asserts the cash row count, not the response status. A 201 on the retry
     * is not itself the harm — the harm is the second cash movement, and that
     * is what is asserted.
     */
    public function test_a_retry_after_pruning_must_not_book_the_cash_movement_again(): void
    {
        $key = 'ret-reexec-key';
        [$shop, $claim] = $this->commitCashWithUnresolvedClaim($key);

        IdempotencyKey::whereKey($claim->id)->update(['created_at' => now()->subHours(49)]);
        $this->artisan('mobile:prune-idempotency-keys')->assertSuccessful();

        // The client still holds the key it never saw acknowledged.
        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), [
            'X-Idempotency-Key' => $key,
        ]);

        $this->assertSame(
            1,
            $this->cashCount((int) $shop->id),
            'DUPLICATE CASH: pruning the unresolved claim re-permitted the money operation',
        );
    }

    /**
     * The in-flight sentinel must never escape as an HTTP status.
     *
     * `response_status` is an internal column. 0 is not a valid HTTP status,
     * so a replay path that passed it to `response()->json($body, $status)`
     * would throw inside the middleware and surface as a 500 — on a route
     * whose entire purpose is to answer safely when it cannot be sure.
     *
     * Asserted as a RANGE, not as `!== 0`. A corrupted value outside 100-599
     * is worse than the sentinel, because it would report something definite
     * about an operation whose outcome is unknown.
     */
    public function test_the_in_flight_sentinel_never_escapes_as_an_http_status(): void
    {
        $key = 'ret-sentinel-key';
        [$shop] = $this->commitCashWithUnresolvedClaim($key);

        $retry = $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), [
            'X-Idempotency-Key' => $key,
        ]);

        $this->assertGreaterThanOrEqual(100, $retry->status(), 'the internal sentinel leaked into the response status');
        $this->assertLessThanOrEqual(599, $retry->status());
        $this->assertSame(409, $retry->status());
        $this->assertSame('idempotency_in_flight', $retry->json('errors.0.code'));
    }

    /**
     * A corrupted status is treated as unknown, not replayed as fact.
     *
     * The range check exists for values the sentinel constant does not cover.
     * Written directly to the column because no code path produces one — that
     * is the point: this asserts the middleware's behaviour if the invariant
     * is ever violated by something outside it.
     */
    public function test_a_corrupted_response_status_is_refused_rather_than_replayed(): void
    {
        [, $shop] = $this->actAsOwner();

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), [
            'X-Idempotency-Key' => 'ret-corrupt-key',
        ])->assertCreated();

        IdempotencyKey::where('shop_id', $shop->id)
            ->where('key', 'ret-corrupt-key')
            ->update(['response_status' => 42]);

        $retry = $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), [
            'X-Idempotency-Key' => 'ret-corrupt-key',
        ]);

        $this->assertSame(409, $retry->status(), 'a nonsense status must not be handed back as a real one');
        $this->assertSame('idempotency_in_flight', $retry->json('errors.0.code'));
        $this->assertSame(1, $this->cashCount((int) $shop->id), 'and it must not re-run the operation either');
    }

    // ────────────────────────────────────────────────────────────────────
    // Controls — the pruner must still do its actual job
    // ────────────────────────────────────────────────────────────────────

    /**
     * [POSITIVE CONTROL] A RESOLVED claim past the threshold is still pruned.
     *
     * Without this, the fix above could be "never delete anything" and both
     * tests would still pass. This pins the correction to unresolved rows
     * only, so the table stays bounded for the ordinary case.
     */
    public function test_a_resolved_claim_past_the_threshold_is_still_pruned(): void
    {
        [, $shop] = $this->actAsOwner();

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), [
            'X-Idempotency-Key' => 'ret-resolved-key',
        ])->assertCreated();

        $claim = IdempotencyKey::where('shop_id', $shop->id)->where('key', 'ret-resolved-key')->sole();
        $this->assertSame(201, (int) $claim->response_status, 'expected a completed claim to carry its real status');

        IdempotencyKey::whereKey($claim->id)->update(['created_at' => now()->subHours(49)]);
        $this->artisan('mobile:prune-idempotency-keys')->assertSuccessful();

        $this->assertSame(
            0,
            IdempotencyKey::where('shop_id', $shop->id)->where('key', 'ret-resolved-key')->count(),
            'the pruner stopped doing its job — resolved rows must still be reaped',
        );
    }

    /**
     * [POSITIVE CONTROL] A RECENT resolved claim is left alone.
     *
     * Guards the other direction: a correction that pruned too eagerly would
     * destroy replay for live traffic.
     */
    public function test_a_recent_resolved_claim_is_left_alone(): void
    {
        [, $shop] = $this->actAsOwner();

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), [
            'X-Idempotency-Key' => 'ret-recent-key',
        ])->assertCreated();

        $this->artisan('mobile:prune-idempotency-keys')->assertSuccessful();

        $this->assertSame(
            1,
            IdempotencyKey::where('shop_id', $shop->id)->where('key', 'ret-recent-key')->count(),
            'a claim inside the retention window must survive',
        );
    }

    /**
     * [SCOPE CONTROL] In THIS failure mode the audit trail is intact.
     *
     * Worth pinning, because it bounds what an operator can do about an
     * unresolved claim. The injected failure here is on the middleware's
     * claim-completion write, which happens after the controller has finished
     * — so the cash row AND its audit row both committed, and the movement is
     * reconcilable from the trail even though the client never learned that.
     *
     * An earlier draft of this test was named for the opposite claim — that
     * the audit row was missing — and it PASSED, because it asserted a count
     * of 1 while its message said "without its audit row". Nothing in it ever
     * injected an audit failure. Recorded rather than quietly deleted: a test
     * whose name outruns its assertions is the same class of defect as the
     * 404 false pass in MobileIdempotencyRetryIntegrityTest.
     *
     * The genuine audit-write gap is a DIFFERENT failure mode and a different
     * finding (S3-10, CashBookController writes cash and audit with no
     * transaction between them). Its evidence lives in
     * MobileIdempotencyRetryIntegrityTest, which actually injects that
     * failure. It is not established here and is not claimed here.
     */
    public function test_a_lost_claim_completion_still_leaves_the_audit_trail_intact(): void
    {
        [$shop] = $this->commitCashWithUnresolvedClaim('ret-audit-intact-key');

        $this->assertSame(1, $this->cashCount((int) $shop->id));
        $this->assertSame(
            1,
            AuditLog::withoutTenant()->where('shop_id', $shop->id)->where('action', 'cash_in')->count(),
            'expected the audit row to have committed — the injected failure is after the controller, not inside it',
        );
    }
}

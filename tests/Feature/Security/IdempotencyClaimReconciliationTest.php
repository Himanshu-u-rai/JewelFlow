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
 * The operator tool for an UNRESOLVED idempotency claim (S3-09b follow-up).
 *
 * An unresolved claim is a keyed mutation whose outcome the server never
 * learned. The middleware refuses every same-key retry while it exists, and
 * the pruner retains it on purpose. What was missing was a supported way for
 * an operator who HAS established the outcome to record it — anything else
 * would be manual SQL against a table whose rows stand between a retry and a
 * duplicate cash entry.
 *
 * The tool decides nothing. It has no age option and no bulk option. It acts
 * on one claim, only with an outcome, evidence and an approver, only with
 * --confirm, and writes the change and an audit row in one transaction:
 *
 *   * not-committed → the claim is released, so the same key may run.
 *   * committed     → the key keeps refusing, now with a definite answer
 *                     instead of "in progress", and no longer counts as
 *                     unresolved.
 *
 * Both states are produced through the real cashbook route with injected
 * failures, not hand-inserted, so the evidence each test reconciles against
 * is a real ledger state.
 */
class IdempotencyClaimReconciliationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const COMMAND = 'mobile:idempotency-claims';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function postCash(int $shopId, string $key): \Illuminate\Testing\TestResponse
    {
        TenantContext::set($shopId);

        return $this->withHeaders(['X-Idempotency-Key' => $key])->postJson('/api/mobile/v1/cashbook', [
            'type' => 'in', 'amount' => 2500.00, 'source_type' => 'other',
            'payment_mode' => 'cash', 'description' => 'Counter float top-up',
        ]);
    }

    private function cashCount(int $shopId): int
    {
        return CashTransaction::withoutTenant()->where('shop_id', $shopId)->count();
    }

    private function auditRows(int $claimId): \Illuminate\Support\Collection
    {
        return AuditLog::withoutTenant()
            ->where('action', 'idempotency_claim_reconciled')
            ->where('model_id', $claimId)
            ->get();
    }

    /** One-shot failure: the first matching event throws, later ones pass. */
    private function failOnce(string $model, string $event, string $message): callable
    {
        $fired = false;
        $model::$event(function () use (&$fired, $message) {
            if ($fired) {
                return;
            }
            $fired = true;
            throw new \RuntimeException($message);
        });

        // Not `fn () => $fired`: arrow functions capture by value at creation,
        // so it would report false forever (the S3-10 harness bug, repeated).
        return function () use (&$fired) {
            return $fired;
        };
    }

    /** Cash committed, outcome never recorded. */
    private function committedButUnresolved(string $key): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        $fired = $this->failOnce(IdempotencyKey::class, 'updating', 'died after the commit, before recording the outcome');

        $this->postCash((int) $shop->id, $key)->assertCreated();

        $this->assertTrue($fired());
        $claim = IdempotencyKey::where('shop_id', $shop->id)->where('key', $key)->sole();
        $this->assertSame(0, (int) $claim->response_status);
        $this->assertSame(1, $this->cashCount((int) $shop->id));

        return [$shop, $claim];
    }

    /** Died inside the business transaction: nothing committed, claim retained on the 5xx. */
    private function notCommittedButUnresolved(string $key): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        $fired = $this->failOnce(AuditLog::class, 'creating', 'died inside the cash/audit transaction');

        $this->assertSame(500, $this->postCash((int) $shop->id, $key)->getStatusCode());

        $this->assertTrue($fired());
        $claim = IdempotencyKey::where('shop_id', $shop->id)->where('key', $key)->sole();
        $this->assertSame(0, (int) $claim->response_status);
        $this->assertSame(0, $this->cashCount((int) $shop->id));

        return [$shop, $claim];
    }

    private function reconcile(int $claimId, array $options): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan(self::COMMAND, array_merge(['--reconcile' => $claimId], $options));
    }

    // ────────────────────────────────────────────────────────────────────

    public function test_listing_shows_unresolved_claims_only_and_writes_nothing(): void
    {
        [$shop, $claim] = $this->committedButUnresolved('rec-list');
        $this->postCash((int) $shop->id, 'rec-list-resolved')->assertCreated();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('rec-list')
            ->doesntExpectOutputToContain('rec-list-resolved')
            ->assertExitCode(0);

        $this->assertSame(0, (int) $claim->fresh()->response_status);
    }

    public function test_without_confirm_nothing_is_written(): void
    {
        [, $claim] = $this->notCommittedButUnresolved('rec-dry-run');

        $this->reconcile($claim->id, [
            '--outcome' => 'not-committed', '--evidence' => 'no cash row after staking', '--approved-by' => 'A. Owner',
        ])->expectsOutputToContain('DRY RUN')->assertExitCode(0);

        $this->assertNotNull($claim->fresh(), 'still there');
        $this->assertCount(0, $this->auditRows($claim->id));
    }

    public function test_evidence_approver_and_a_known_outcome_are_all_required(): void
    {
        [, $claim] = $this->notCommittedButUnresolved('rec-required');

        foreach ([
            ['--outcome' => 'not-committed', '--approved-by' => 'A. Owner'],
            ['--outcome' => 'not-committed', '--evidence' => 'checked'],
            ['--outcome' => 'probably-fine', '--evidence' => 'checked', '--approved-by' => 'A. Owner'],
            ['--evidence' => 'checked', '--approved-by' => 'A. Owner'],
        ] as $options) {
            $this->reconcile($claim->id, $options + ['--confirm' => true])->assertExitCode(1);
        }

        $this->assertSame(0, (int) $claim->fresh()->response_status);
        $this->assertCount(0, $this->auditRows($claim->id));
    }

    public function test_a_resolved_claim_is_refused(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        $this->postCash((int) $shop->id, 'rec-resolved')->assertCreated();
        $claim = IdempotencyKey::where('key', 'rec-resolved')->sole();

        $this->reconcile($claim->id, [
            '--outcome' => 'not-committed', '--evidence' => 'x', '--approved-by' => 'A. Owner', '--confirm' => true,
        ])->assertExitCode(1);

        $this->assertSame(201, (int) $claim->fresh()->response_status, 'a resolved claim is never released');
    }

    /**
     * NOT COMMITTED: released, audited, and the operator's retry under the same
     * key books the entry exactly once.
     */
    public function test_not_committed_releases_the_key_and_records_why(): void
    {
        [$shop, $claim] = $this->notCommittedButUnresolved('rec-not-committed');
        $before = $claim->getAttributes();

        $this->reconcile($claim->id, [
            '--outcome' => 'not-committed',
            '--evidence' => 'cash_transactions: no row for this user after the claim was staked',
            '--approved-by' => 'A. Owner',
            '--confirm' => true,
        ])->assertExitCode(0);

        $this->assertNull($claim->fresh());

        $audit = $this->auditRows($claim->id)->sole();
        $this->assertSame((int) $shop->id, (int) $audit->shop_id);
        $this->assertSame('not-committed', $audit->data['outcome']);
        $this->assertSame('cash_transactions: no row for this user after the claim was staked', $audit->data['evidence']);
        $this->assertSame('A. Owner', $audit->actor['approved_by']);
        $this->assertSame($before['key'], $audit->before['key']);
        $this->assertNull($audit->after);

        $this->postCash((int) $shop->id, 'rec-not-committed')->assertCreated();
        $this->assertSame(1, $this->cashCount((int) $shop->id), 'booked once, by the retry');
    }

    /**
     * COMMITTED: the key keeps refusing — with a definite answer rather than
     * "in progress" — and no retry can book a second entry.
     */
    public function test_committed_keeps_the_key_refused_with_a_definite_answer(): void
    {
        [$shop, $claim] = $this->committedButUnresolved('rec-committed');

        $this->reconcile($claim->id, [
            '--outcome' => 'committed',
            '--evidence' => 'cash_transactions row matches amount, user and time',
            '--approved-by' => 'A. Owner',
            '--confirm' => true,
        ])->assertExitCode(0);

        $retry = $this->postCash((int) $shop->id, 'rec-committed');

        $retry->assertStatus(409);
        $this->assertSame('idempotency_outcome_reconciled', $retry->json('errors.0.code'));
        $this->assertSame(1, $this->cashCount((int) $shop->id), 'never a second entry');

        $audit = $this->auditRows($claim->id)->sole();
        $this->assertSame('committed', $audit->data['outcome']);
        $this->assertSame(409, $audit->after['response_status']);

        $this->artisan(self::COMMAND)->doesntExpectOutputToContain('rec-committed')->assertExitCode(0);
    }

    // ────────────────────────────────────────────────────────────────────
    // XR-02 — reconciliation and retention compose safely
    // ────────────────────────────────────────────────────────────────────

    private function age(IdempotencyKey $claim, int $days): void
    {
        IdempotencyKey::whereKey($claim->id)->update(['created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days)]);
    }

    /**
     * An old claim reconciled as committed must not be pruned before the client
     * has any chance to see the answer. Reconciling changes its status to 409
     * but keeps the old created_at, so a pruner that removes every non-zero
     * status older than 48 h deletes it on its next run — and the same key then
     * books the entry a second time.
     */
    public function test_an_aged_committed_reconciliation_survives_the_real_pruner(): void
    {
        [$shop, $claim] = $this->committedButUnresolved('rec-aged-committed');
        $this->age($claim, 3);

        $this->reconcile($claim->id, [
            '--outcome' => 'committed', '--evidence' => 'cash row found', '--approved-by' => 'A. Owner', '--confirm' => true,
        ])->assertExitCode(0);

        $this->artisan('mobile:prune-idempotency-keys')->assertSuccessful();

        $retry = $this->postCash((int) $shop->id, 'rec-aged-committed');
        $retry->assertStatus(409);
        $this->assertSame('idempotency_outcome_reconciled', $retry->json('errors.0.code'));
        $this->assertSame(1, $this->cashCount((int) $shop->id), 'never a second entry');
    }

    /**
     * The middleware treats every status outside 100–599 as unresolved and
     * refuses a retry. The pruner must retain exactly what the middleware
     * refuses — not only the sentinel 0 — or a corrupted status is deleted
     * after 48 h and the retry runs.
     */
    public function test_an_out_of_range_status_is_retained_by_the_pruner_and_still_refused(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        $this->postCash((int) $shop->id, 'rec-out-of-range')->assertCreated();
        $claim = IdempotencyKey::where('key', 'rec-out-of-range')->sole();

        foreach ([-1, 99, 700] as $status) {
            IdempotencyKey::whereKey($claim->id)->update(['response_status' => $status]);
            $this->age($claim, 3);

            $this->artisan('mobile:prune-idempotency-keys')->assertSuccessful();

            $this->assertTrue(IdempotencyKey::whereKey($claim->id)->exists(), "status {$status} is unresolved to the middleware, so it is retained");
            $this->postCash((int) $shop->id, 'rec-out-of-range')->assertStatus(409);
            $this->assertSame(1, $this->cashCount((int) $shop->id), "status {$status}: no second entry");
        }
    }

    /** [CONTROL] An ordinary completed claim past the window is still pruned. */
    public function test_an_ordinary_completed_claim_is_still_pruned(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        $this->postCash((int) $shop->id, 'rec-ordinary')->assertCreated();
        $claim = IdempotencyKey::where('key', 'rec-ordinary')->sole();
        $this->age($claim, 3);

        $this->artisan('mobile:prune-idempotency-keys')->assertSuccessful();

        $this->assertFalse(IdempotencyKey::whereKey($claim->id)->exists());
    }
}

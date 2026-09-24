<?php

namespace Tests\Feature\Security;

use App\Models\IdempotencyKey;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-09e — a replayed response dropped the headers the route's own contract
 * requires, and carried only its body.
 *
 * ─── REPAIRED. These tests were a regression lock first. ──────────────────
 *
 * They were written against the defect and passed against it, with failure
 * messages reading "appears repaired". The two defect assertions are now
 * inverted, and they failed against the unrepaired middleware before the
 * repair landed.
 *
 * Repair: `EnsureIdempotency` persists the headers the controller set that a
 * replay must carry — `ETag` and `X-Has-Entity-Tag`, the only two any of the
 * 16 routes set — in a nullable `idempotency_keys.response_headers` column,
 * and restores them on replay. An allowlist, not every header: a replayed
 * `Set-Cookie` or rate-limit header would describe the wrong request.
 *
 * The earlier reason for not repairing — that the column would sit under
 * `payment-idempotency-rollback-constraints.md` — was wrong on the facts.
 * That document governs `invoice_payment_claims` (S3-07b); it mentions
 * `idempotency_keys` only as the table the pruner targets. The column is
 * additive and nullable: baseline code ignores it, and a claim completed
 * without it replays exactly as before (test below).
 *
 * ─── How this was found, and what it is NOT ───────────────────────────────
 *
 * Found while classifying the 12 remaining idempotency-protected routes
 * against the repaired middleware (§7c). It is NOT a regression introduced by
 * that repair. I checked: the pre-repair blob `6a8c4cc` stores exactly the
 * same two columns and rebuilds the reply the same way —
 *
 *     $response = response()->json($body, $status);
 *
 * — so this gap predates S3-09 and survived it untouched. Recording that
 * explicitly because a finding raised during a repair's verification is easy
 * to misfile as caused by the repair.
 *
 * ─── The defect ───────────────────────────────────────────────────────────
 *
 * `EnsureIdempotency::completeClaim` persists only `response_status` and a
 * json_decode'd `response_body`. Headers are not persisted, and the replay
 * path reconstructs the response from those two columns alone. Every header
 * the original response carried is therefore dropped on replay.
 *
 * For most of the 16 routes that is cosmetic. For the two `PATCH` routes it
 * is not, because their contract is built ON a header:
 *
 *   * `EmitsEntityTag::assertIfMatchOrFail` makes `If-Match` MANDATORY — a
 *     missing header is 428, a stale one is 412.
 *   * The tag is `sha256(id|updated_at|class)`. `updated_at` moves on every
 *     successful write, so a client CANNOT compute the next tag itself. Its
 *     only source is the `ETag` response header.
 *
 * So the retry path and the concurrency contract disagree. A client that
 * successfully PATCHes, loses the response to a dropped connection, and
 * retries under the same key — exactly the sequence idempotency exists to
 * serve — gets a 200 that tells it the write succeeded but withholds the one
 * value it needs to make its next write. It is wedged until it re-GETs.
 *
 * ─── Severity, stated honestly ────────────────────────────────────────────
 *
 * This is an AVAILABILITY and contract-fidelity defect, not a tenancy or
 * money defect. Nothing is written twice, nothing leaks across shops, and the
 * ledger is untouched. A client that re-GETs recovers on its own. It is
 * recorded because the recovery is undocumented and the 428/412 the client
 * hits first is indistinguishable, from the client's side, from a genuine
 * conflict with another operator.
 */
class MobileIdempotencyReplayFidelityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /**
     * PATCH an item, then replay the same key, and report both ETags.
     *
     * TenantContext is re-set before each request because `EnsureTenantUser`
     * clears it in a `finally`, and under PHPUnit
     * `BelongsToShop::resolveTenantShopId()` returns null early on
     * `runningInConsole()` instead of falling back to the authenticated user.
     * Test-environment characteristic, not a production bug.
     */
    private function patchTwiceUnderOneKey(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);

        $item = $this->createItem((int) $shop->id, null, ['selling_price' => 1000]);

        // The tag the client legitimately holds, taken from a real read.
        TenantContext::set((int) $shop->id);
        $read = $this->getJson("/api/mobile/v1/items/{$item->id}");
        $read->assertOk();
        $originalTag = $read->headers->get('ETag');

        $headers = [
            'X-Idempotency-Key' => 'patch-replay-fidelity',
            'If-Match' => $originalTag,
        ];
        $payload = ['selling_price' => 2500];

        TenantContext::set((int) $shop->id);
        $first = $this->withHeaders($headers)->patchJson("/api/mobile/v1/items/{$item->id}", $payload);

        TenantContext::set((int) $shop->id);
        $replay = $this->withHeaders($headers)->patchJson("/api/mobile/v1/items/{$item->id}", $payload);

        return [$first, $replay, $originalTag];
    }

    // ────────────────────────────────────────────────────────────────────
    // Preconditions — so a green run cannot mean "the replay never happened"
    // ────────────────────────────────────────────────────────────────────

    /**
     * The live PATCH must succeed and must emit an ETag.
     *
     * This deliberately does NOT assert the tag CHANGED after the write. I
     * wrote that assertion first and it failed — but for a reason that has
     * nothing to do with replay: `items.updated_at` is `timestamp(0)`, so a
     * create and a PATCH in the same wall-clock second yield an identical
     * validator. That is a separate and more serious defect, characterized on
     * its own in `EntityTagResolutionTest`. Asserting it here would couple two
     * unrelated findings and make this file fail for the wrong reason.
     */
    public function test_precondition_the_first_patch_succeeds_and_emits_an_entity_tag(): void
    {
        [$first] = $this->patchTwiceUnderOneKey();

        $first->assertOk();
        $this->assertNotNull($first->headers->get('ETag'), 'The live PATCH must emit an ETag.');
    }

    public function test_precondition_the_second_request_is_served_as_a_replay(): void
    {
        [, $replay] = $this->patchTwiceUnderOneKey();

        $replay->assertOk();
        $this->assertSame(
            'true',
            $replay->headers->get('X-Idempotent-Replay'),
            'The second request must be a replay — otherwise the controller ran again and this test proves nothing.',
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // The defect
    // ────────────────────────────────────────────────────────────────────

    /**
     * [REPAIR] The replay carries the original response's entity tag.
     */
    public function test_a_replayed_patch_carries_the_entity_tag_of_the_original(): void
    {
        [$first, $replay] = $this->patchTwiceUnderOneKey();

        $this->assertNotNull(
            $first->headers->get('ETag'),
            'The live PATCH must carry an ETag for the comparison to mean anything.',
        );

        $this->assertSame($first->headers->get('ETag'), $replay->headers->get('ETag'),
            'S3-09e: the replay must hand back the tag the original response carried.');
        $this->assertSame('yes', $replay->headers->get('X-Has-Entity-Tag'));
    }

    /**
     * [REPAIR] Only the allowlisted headers are persisted — nothing that
     * describes the original request rather than the resource.
     */
    public function test_only_the_replayable_headers_are_persisted(): void
    {
        [$first] = $this->patchTwiceUnderOneKey();

        $stored = IdempotencyKey::withoutGlobalScopes()
            ->where('key', 'patch-replay-fidelity')
            ->value('response_headers');

        $this->assertSame(
            ['ETag' => $first->headers->get('ETag'), 'X-Has-Entity-Tag' => 'yes'],
            $stored,
        );
    }

    /**
     * [COMPATIBILITY] A claim completed without the column — by baseline code
     * during the expand window, or before this repair — replays as it always
     * did: status and body, no entity tag. Nothing is invented for it.
     */
    public function test_a_claim_recorded_without_headers_replays_as_before(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);

        $item = $this->createItem((int) $shop->id, null, ['selling_price' => 1000]);

        TenantContext::set((int) $shop->id);
        $tag = $this->getJson("/api/mobile/v1/items/{$item->id}")->headers->get('ETag');
        $headers = ['X-Idempotency-Key' => 'legacy-claim', 'If-Match' => $tag];

        TenantContext::set((int) $shop->id);
        $this->withHeaders($headers)->patchJson("/api/mobile/v1/items/{$item->id}", ['selling_price' => 2500])->assertOk();

        IdempotencyKey::withoutGlobalScopes()->where('key', 'legacy-claim')->update(['response_headers' => null]);

        TenantContext::set((int) $shop->id);
        $replay = $this->withHeaders($headers)->patchJson("/api/mobile/v1/items/{$item->id}", ['selling_price' => 2500]);

        $replay->assertOk();
        $this->assertSame('true', $replay->headers->get('X-Idempotent-Replay'));
        $this->assertNull($replay->headers->get('ETag'));
    }

    /**
     * [DEGRADATION] If the headers cannot be written — the column dropped by a
     * migration rollback while this code still serves — the claim is still
     * resolved and replays without them, rather than staying in flight and
     * refusing every retry.
     *
     * The exception is PostgreSQL's own: the column is really dropped and the
     * claim's update really run, inside a savepoint (realFailure). It is
     * raised from `updating` because a failed statement in the test's own
     * transaction would abort it. The rollback rehearsal measures the same
     * case with the column dropped for real, outside any test transaction.
     */
    public function test_a_failed_header_write_still_resolves_the_claim(): void
    {
        IdempotencyKey::updating(function (IdempotencyKey $claim) {
            if ($claim->isDirty('response_headers')) {
                throw $this->realFailure($claim, 'alter table idempotency_keys drop column response_headers');
            }
        });

        [$first, $replay] = $this->patchTwiceUnderOneKey();

        $first->assertOk();
        $replay->assertOk();
        $this->assertSame('true', $replay->headers->get('X-Idempotent-Replay'), 'resolved, so replayed — not refused as in flight');
        $this->assertNull($replay->headers->get('ETag'), 'only the headers were lost');
        $this->assertSame(200, IdempotencyKey::withoutGlobalScopes()
            ->where('key', 'patch-replay-fidelity')->value('response_status'));
    }

    /**
     * [XR-07, second review] Laravel appends the SQL to a QueryException's
     * message, and the completion UPDATE always names response_headers. So
     * matching the message took ANY failure of that statement for a missing
     * column, and completed the claim without its headers.
     *
     * The failure here is real and unrelated: PostgreSQL refuses this exact
     * UPDATE under a check constraint (23514), not for a missing column.
     */
    public function test_an_unrelated_failure_naming_the_column_leaves_the_claim_unresolved(): void
    {
        IdempotencyKey::updating(function (IdempotencyKey $claim) {
            if ($claim->isDirty('response_headers')) {
                throw $this->realFailure($claim,
                    'alter table idempotency_keys add constraint xr07_probe check (response_headers is null) not valid');
            }
        });

        [$first, $retry] = $this->patchTwiceUnderOneKey();

        $first->assertOk();
        $this->assertSame(0, (int) IdempotencyKey::withoutGlobalScopes()
            ->where('key', 'patch-replay-fidelity')->value('response_status'),
            'the claim must stay unresolved, not complete without its replay headers');
        $retry->assertStatus(409);
        $this->assertSame('idempotency_in_flight', $retry->json('errors.0.code'));
    }

    /**
     * Run the claim's pending UPDATE for real after $ddl, inside a savepoint
     * that undoes both, and return PostgreSQL's exception.
     */
    private function realFailure(IdempotencyKey $claim, string $ddl): QueryException
    {
        try {
            DB::transaction(function () use ($claim, $ddl) {
                DB::statement($ddl);
                DB::table('idempotency_keys')->where('id', $claim->id)->update($claim->getDirty());
            });
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('the probe statement was expected to fail');
    }

    /**
     * [XR-07] The boundary between the completion writes. A retry that reads
     * the claim at ANY moment must never find it resolved without the headers
     * its response carried. Recorded after every update of the claim row —
     * the persisted state a concurrent retry would replay.
     */
    public function test_the_claim_is_never_resolved_without_its_replay_headers(): void
    {
        $observed = [];
        IdempotencyKey::updated(function (IdempotencyKey $claim) use (&$observed) {
            $row = IdempotencyKey::withoutGlobalScopes()->whereKey($claim->id)->first(['response_status', 'response_headers']);
            $observed[] = [(int) $row->response_status, $row->response_headers];
        });

        [$first] = $this->patchTwiceUnderOneKey();
        $this->assertNotNull($first->headers->get('ETag'));

        $resolvedWithoutHeaders = array_filter($observed, fn ($state) => $state[0] >= 200 && $state[0] < 300 && empty($state[1]));
        $this->assertSame([], array_values($resolvedWithoutHeaders),
            'XR-07: at some point the claim was persisted as resolved without its ETag — a retry then would replay success without it');
    }

    /**
     * [REPAIR] The consequence, end to end: a client that takes the tag the
     * replay offered can make its next write. Before the repair it got 428.
     */
    public function test_a_client_following_the_replay_can_make_its_next_write(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);

        $item = $this->createItem((int) $shop->id, null, ['selling_price' => 1000]);

        TenantContext::set((int) $shop->id);
        $originalTag = $this->getJson("/api/mobile/v1/items/{$item->id}")->headers->get('ETag');

        $first = ['X-Idempotency-Key' => 'patch-wedge-probe', 'If-Match' => $originalTag];

        TenantContext::set((int) $shop->id);
        $this->withHeaders($first)->patchJson("/api/mobile/v1/items/{$item->id}", ['selling_price' => 2500]);

        TenantContext::set((int) $shop->id);
        $replay = $this->withHeaders($first)->patchJson("/api/mobile/v1/items/{$item->id}", ['selling_price' => 2500]);

        // The client now proceeds using what the replay gave it.
        TenantContext::set((int) $shop->id);
        $next = $this->withHeaders([
            'X-Idempotency-Key' => 'patch-wedge-probe-2',
            'If-Match' => (string) $replay->headers->get('ETag'),
        ])->patchJson("/api/mobile/v1/items/{$item->id}", ['selling_price' => 3100]);

        $this->assertSame(200, $next->getStatusCode(),
            'S3-09e: a client following the replay must not be wedged (it got 428 before the repair).');
    }

    // ────────────────────────────────────────────────────────────────────
    // Control — the wedge is caused by the replay, not by the route
    // ────────────────────────────────────────────────────────────────────

    /**
     * A client that follows the LIVE response is not wedged.
     *
     * Kept from the characterization, where it proved the replay — not the
     * route — caused the 428. It now pins that the repair did not change the
     * live path.
     */
    public function test_control_a_client_following_the_live_response_can_make_its_next_write(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);

        $item = $this->createItem((int) $shop->id, null, ['selling_price' => 1000]);

        TenantContext::set((int) $shop->id);
        $originalTag = $this->getJson("/api/mobile/v1/items/{$item->id}")->headers->get('ETag');

        TenantContext::set((int) $shop->id);
        $live = $this->withHeaders([
            'X-Idempotency-Key' => 'patch-live-control',
            'If-Match' => $originalTag,
        ])->patchJson("/api/mobile/v1/items/{$item->id}", ['selling_price' => 2500]);

        $live->assertOk();

        TenantContext::set((int) $shop->id);
        $next = $this->withHeaders([
            'X-Idempotency-Key' => 'patch-live-control-2',
            'If-Match' => (string) $live->headers->get('ETag'),
        ])->patchJson("/api/mobile/v1/items/{$item->id}", ['selling_price' => 3100]);

        $next->assertOk();
    }
}

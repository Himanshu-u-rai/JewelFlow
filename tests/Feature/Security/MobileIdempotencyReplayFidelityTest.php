<?php

namespace Tests\Feature\Security;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-09e — a replayed response drops the headers the route's own contract
 * requires, and carries only its body.
 *
 * ─── CHARACTERIZATION ONLY. NOT REPAIRED. ─────────────────────────────────
 *
 * These tests pass against the CURRENT code. They are a regression lock, not
 * proof of a repair. Every assertion that encodes the defect says so in its
 * failure message, so a future fix reads as "S3-09e repaired", not as a break.
 *
 * Deliberately not repaired here. The fix is to persist the response headers
 * alongside the body, and the only place to persist them is the
 * `idempotency_keys` table — which is the subject of the still-open S3-09 and
 * is governed by `payment-idempotency-rollback-constraints.md`. Adding a
 * column to that table while its rollback path is constrained couples an
 * availability fix to a money-path finding, for a defect that self-heals the
 * moment the client re-GETs. That trade is the operator's to make, not mine.
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
     * [LOCKS THE DEFECT] The replay carries no ETag at all.
     *
     * The live response carries one; the replay's is null. A client that
     * trusts the replay's 200 has no value to use as its next `If-Match`.
     *
     * Written as a lock rather than as a demand, for the reason in the class
     * docblock: repairing it means persisting response headers, and the only
     * place to persist them is `idempotency_keys` — the table under the open
     * S3-09 rollback constraints. If that repair lands, this test fails and
     * the message below says so.
     */
    public function test_a_replayed_patch_currently_drops_the_entity_tag(): void
    {
        [$first, $replay] = $this->patchTwiceUnderOneKey();

        $this->assertNotNull(
            $first->headers->get('ETag'),
            'The live PATCH must carry an ETag for the comparison to mean anything.',
        );

        $this->assertNull(
            $replay->headers->get('ETag'),
            'The replay now carries an ETag — S3-09e appears repaired. Update the handoff and '
                . 'switch this assertion to assertSame() against the live response.',
        );
    }

    /**
     * [LOCKS THE DEFECT] The consequence, demonstrated end to end.
     *
     * Takes whatever tag the replay actually offered and tries the next write
     * with it — precisely what a well-behaved client would do. It gets 428
     * `precondition_required`, because the replay offered nothing.
     */
    public function test_a_client_following_the_replay_cannot_make_its_next_write(): void
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

        $this->assertSame(
            428,
            $next->getStatusCode(),
            'The client is no longer wedged after a replay — S3-09e appears repaired. Update the '
                . 'handoff and invert this assertion.',
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // Control — the wedge is caused by the replay, not by the route
    // ────────────────────────────────────────────────────────────────────

    /**
     * A client that follows the LIVE response is not wedged.
     *
     * Load-bearing. Without it, the 428 above would be equally explained by
     * `PATCH /items` being unusable in sequence for some unrelated reason.
     * This shows the second write works fine when the client is given the
     * header the replay withheld — so the replay is the cause.
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

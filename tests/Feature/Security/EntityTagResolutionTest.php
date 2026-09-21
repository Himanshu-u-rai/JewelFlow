<?php

namespace Tests\Feature\Security;

use App\Models\Item;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-12 — the `If-Match` validator has one-second resolution, so a write can
 * be silently lost.
 *
 * ─── CHARACTERIZATION ONLY. NOT REPAIRED. ─────────────────────────────────
 *
 * This file records behaviour that exists today. It is deliberately written
 * to pass against the CURRENT code, so it is a regression lock, NOT proof of
 * a repair. If someone later fixes the resolution, the two tests marked
 * "LOCKS THE DEFECT" will fail — and that failure is the fix landing, not a
 * regression. Read their messages before changing them.
 *
 * ─── How it was found ─────────────────────────────────────────────────────
 *
 * Not by looking for it. While testing idempotent replay on `PATCH /items`
 * (S3-09e) I asserted, as a precondition, that a successful write moves the
 * ETag. It failed. My first hypothesis was that the PATCH had silently no-op'd.
 * That was WRONG — a probe showed status 200 and `selling_price` 1000 → 2500
 * genuinely persisted, with the tag unchanged either side. Recording the
 * wrong first hypothesis because the correction is the evidence.
 *
 * ─── Root cause, measured not inferred ────────────────────────────────────
 *
 * `EmitsEntityTag::entityTagFor` hashes `(id | updated_at ISO-8601 | class)`.
 * Two independent facts make that one-second granular:
 *
 *   1. The format is `DateTimeInterface::ATOM` = `Y-m-d\TH:i:sP`, which has no
 *      sub-second field.
 *   2. More fundamentally, the COLUMN has no sub-second data to offer:
 *
 *        select datetime_precision from information_schema.columns
 *         where table_name = 'items' and column_name = 'updated_at';
 *        -- 0
 *
 * Point 2 is why this is not a format bug. Widening the format string would
 * change nothing; Postgres is storing `timestamp(0)` and truncating.
 *
 * ─── The consequence ──────────────────────────────────────────────────────
 *
 * `assertIfMatchOrFail` is the app's optimistic-concurrency control: 428 when
 * `If-Match` is absent, 412 when it is stale. Within a single wall-clock
 * second the validator cannot go stale, so:
 *
 *   operator A GETs the item            → tag T
 *   operator B PATCHes it  (t + 0.2s)   → succeeds, tag is STILL T
 *   operator A PATCHes with If-Match: T → precondition PASSES, B is clobbered
 *
 * That is exactly the lost update `If-Match` exists to prevent. Both writes
 * report 200 and neither operator is told anything was overwritten.
 *
 * ─── Scope and honest severity ────────────────────────────────────────────
 *
 * Affects the two routes that use this concern: `PATCH /items/{item}` and
 * `PATCH /customers/{customer}`.
 *
 * It is NOT a tenancy break — the shop guard is separate and unaffected — and
 * it does not touch the ledger, which is protected by its own immutability
 * triggers rather than by ETags. It is a data-integrity defect on catalogue
 * and customer records, bounded by a one-second window, and it needs two
 * operators editing the same row concurrently. Narrow, but real, and silent.
 *
 * Deliberately not repaired here. The candidate fixes are a schema migration
 * to `timestamp(6)` across the affected tables, or re-basing the validator on
 * row content rather than a timestamp. Both change an established client
 * contract — mobile clients hold these tags — so this is an explicit decision
 * for the operator, not an audit repair to slip in.
 */
class EntityTagResolutionTest extends TestCase
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
     * TenantContext is re-set before every request because `EnsureTenantUser`
     * clears it in a `finally`, and `BelongsToShop::resolveTenantShopId()`
     * returns null early under `runningInConsole()`. Test-environment
     * characteristic, not a production bug.
     */
    private function tag(int $shopId, int $itemId): string
    {
        TenantContext::set($shopId);

        return (string) $this->getJson("/api/mobile/v1/items/{$itemId}")->headers->get('ETag');
    }

    private function patchPrice(int $shopId, int $itemId, string $key, string $ifMatch, float $price)
    {
        TenantContext::set($shopId);

        return $this->withHeaders([
            'X-Idempotency-Key' => $key,
            'If-Match' => $ifMatch,
        ])->patchJson("/api/mobile/v1/items/{$itemId}", ['selling_price' => $price]);
    }

    // ────────────────────────────────────────────────────────────────────
    // The root cause, asserted against the schema itself
    // ────────────────────────────────────────────────────────────────────

    /**
     * The column cannot express sub-second time.
     *
     * Asserted directly so the finding does not rest on my reading of a format
     * constant. If a migration later widens this, this test fails and points
     * at the two below.
     */
    public function test_the_timestamp_backing_the_validator_has_no_subsecond_precision(): void
    {
        $precision = \DB::selectOne(
            'select datetime_precision from information_schema.columns '
                . 'where table_name = ? and column_name = ?',
            ['items', 'updated_at'],
        )->datetime_precision;

        $this->assertSame(
            0,
            (int) $precision,
            'items.updated_at now stores sub-second time. The ETag may no longer be one-second '
                . 'granular — re-check EntityTagResolutionTest, the defect it locks may be fixed.',
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // LOCKS THE DEFECT — these passing means the defect is still present
    // ────────────────────────────────────────────────────────────────────

    /**
     * [LOCKS THE DEFECT] A real, persisted write does not move the validator.
     *
     * The assertions on status and on the stored value are load-bearing: they
     * are what distinguishes "the tag did not move" from "nothing happened",
     * which is the exact mistake I made when I first hit this.
     */
    public function test_a_successful_write_within_the_same_second_leaves_the_entity_tag_unchanged(): void
    {
        [, $shop] = $this->actAsOwner();
        $item = $this->createItem((int) $shop->id, null, ['selling_price' => 1000]);

        $before = $this->tag((int) $shop->id, (int) $item->id);
        $response = $this->patchPrice((int) $shop->id, (int) $item->id, 'etag-resolution-a', $before, 2500);

        $response->assertOk();

        $this->assertSame(
            '2500.00',
            (string) Item::withoutTenant()->find($item->id)->selling_price,
            'The write must actually have persisted, otherwise an unchanged tag proves nothing.',
        );

        $this->assertSame(
            $before,
            $response->headers->get('ETag'),
            'The ETag moved. If this is a deliberate fix, S3-12 is repaired — update the handoff.',
        );
    }

    /**
     * [LOCKS THE DEFECT] The lost update itself, end to end.
     *
     * Two operators, one row, one second. B's write is overwritten by A using
     * a validator A obtained BEFORE B wrote, and A is told 200.
     */
    public function test_a_stale_if_match_is_accepted_and_silently_clobbers_a_concurrent_write(): void
    {
        [, $shop] = $this->actAsOwner();
        $item = $this->createItem((int) $shop->id, null, ['selling_price' => 1000]);
        $shopId = (int) $shop->id;
        $itemId = (int) $item->id;

        // Operator A loads the record and holds its validator.
        $tagHeldByA = $this->tag($shopId, $itemId);

        // Operator B writes first, in the same second.
        $this->patchPrice($shopId, $itemId, 'etag-resolution-b1', $tagHeldByA, 7777)->assertOk();

        $this->assertSame(
            '7777.00',
            (string) Item::withoutTenant()->find($itemId)->selling_price,
            "Operator B's write must land first for this to be a lost update.",
        );

        // Operator A now writes using the validator it read BEFORE B wrote.
        $responseToA = $this->patchPrice($shopId, $itemId, 'etag-resolution-b2', $tagHeldByA, 3333);

        $this->assertNotSame(
            412,
            $responseToA->getStatusCode(),
            'A stale If-Match was correctly rejected — S3-12 appears repaired. Update the handoff.',
        );

        $responseToA->assertOk();

        $this->assertSame(
            '3333.00',
            (string) Item::withoutTenant()->find($itemId)->selling_price,
            "Operator B's value survived, so no update was lost — S3-12 may be repaired.",
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // Positive control — the guard is not simply broken in all directions
    // ────────────────────────────────────────────────────────────────────

    /**
     * A genuinely wrong validator IS still rejected with 412.
     *
     * Without this, the two tests above would be equally satisfied by an
     * `If-Match` check that never rejects anything, which is a different and
     * much larger defect. This shows the mechanism works and the failure is
     * specifically one of time resolution.
     */
    public function test_control_a_plainly_wrong_if_match_is_still_rejected(): void
    {
        [, $shop] = $this->actAsOwner();
        $item = $this->createItem((int) $shop->id, null, ['selling_price' => 1000]);

        $response = $this->patchPrice(
            (int) $shop->id,
            (int) $item->id,
            'etag-resolution-ctl',
            '"deadbeef:item:' . $item->id . '"',
            4242,
        );

        $this->assertSame(412, $response->getStatusCode(), 'A wrong If-Match must be refused.');

        $this->assertSame(
            '1000.00',
            (string) Item::withoutTenant()->find($item->id)->selling_price,
            'A refused precondition must not have written.',
        );
    }

    /** A missing validator is still 428 — the other half of the control. */
    public function test_control_a_missing_if_match_is_still_refused(): void
    {
        [, $shop] = $this->actAsOwner();
        $item = $this->createItem((int) $shop->id, null, ['selling_price' => 1000]);

        TenantContext::set((int) $shop->id);
        $response = $this->withHeaders(['X-Idempotency-Key' => 'etag-resolution-ctl2'])
            ->patchJson("/api/mobile/v1/items/{$item->id}", ['selling_price' => 4242]);

        $this->assertSame(428, $response->getStatusCode(), 'A missing If-Match must be refused.');
    }
}

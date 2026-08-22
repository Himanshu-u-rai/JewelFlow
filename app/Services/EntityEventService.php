<?php

namespace App\Services;

use App\Models\EntityEvent;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EntityEventService
{
    /**
     * Run one audit statement so that its failure can never take down the
     * business operation that triggered it.
     *
     * That guarantee needs two things, and only one of them is a try/catch.
     * Observers fire inside the caller's DB::transaction() (ReturnService,
     * ExchangeService, CreditNoteService, …), and on Postgres a failed
     * statement poisons the entire transaction — every later statement, COMMIT
     * included, is refused until a rollback. Catching the exception on its own
     * is therefore a placebo: the audit row is lost AND the sale still dies,
     * just further downstream with a mystifying 25P02. Wrapping the statement
     * in DB::transaction() opens a SAVEPOINT when we are already nested, and
     * unwinding to it hands the caller back a healthy transaction.
     *
     * Everywhere except production the failure is rethrown: swallowing it in CI
     * is exactly how a missing `snapshot` cast survived unnoticed while every
     * return, sale and job order silently dropped its event. The allow-list is
     * deliberate — staging must stay as forgiving as production, or an audit
     * bug takes the sale down there too.
     *
     * @template TValue
     * @param  callable():TValue  $statement
     * @param  TValue  $fallback  returned in production once the failure is logged
     * @return TValue
     */
    private function guarded(callable $statement, mixed $fallback, string $context, array $meta = []): mixed
    {
        try {
            return DB::transaction($statement);
        } catch (Throwable $e) {
            if (app()->environment('local', 'testing')) {
                throw $e;
            }

            Log::error("EntityEventService: {$context}", $meta + ['exception' => $e]);

            return $fallback;
        }
    }

    /**
     * Record an event against a single entity.
     *
     * Low-level method — all writes must go through here; never raw
     * EntityEvent::create() in observers or controllers.
     *
     * Failure handling lives in guarded(); see the note there for why a bare
     * try/catch is not enough on Postgres.
     */
    public function record(
        int $shopId,
        string $entityType,
        int $entityId,
        string $eventType,
        string $summary,
        int $level = 0,
        ?array $detail = null,
        ?int $actorUserId = null,
        ?Carbon $occurredAt = null,
        ?array $snapshot = null,
    ): ?EntityEvent {
        // Bypass the global shop scope so the service can write on behalf of any
        // shop (e.g. from a queued job or observer that has no auth context).
        // shop_id is always supplied explicitly.
        $event = new EntityEvent();
        $event->forceFill([
            'shop_id'       => $shopId,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'event_type'    => $eventType,
            'summary'       => $summary,
            'level'         => $level,
            'detail'        => $detail,
            'actor_user_id' => $actorUserId,
            'occurred_at'   => $occurredAt ?? Carbon::now(),
            'snapshot'      => $snapshot,
        ]);

        return $this->guarded(
            function () use ($event): EntityEvent {
                $event->saveQuietly();

                return $event;
            },
            fallback: null,
            context:  "failed to record {$eventType} ({$entityType})",
            meta:     [
                'shop_id'     => $shopId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'event_type'  => $eventType,
            ],
        );
    }

    /**
     * Record the same event against multiple entities simultaneously.
     *
     * Example: a sale_finalized event touches both the customer entity AND the
     * invoice entity.
     *
     * @param array<int, array{type: string, id: int}> $entities
     */
    public function recordForMultiple(
        int $shopId,
        array $entities,
        string $eventType,
        string $summary,
        int $level = 0,
        ?array $detail = null,
        ?int $actorUserId = null,
        ?Carbon $occurredAt = null,
        ?array $snapshot = null,
    ): void {
        $ts = $occurredAt ?? Carbon::now();

        foreach ($entities as $entity) {
            $this->record(
                shopId: $shopId,
                entityType: $entity['type'],
                entityId: (int) $entity['id'],
                eventType: $eventType,
                summary: $summary,
                level: $level,
                detail: $detail,
                actorUserId: $actorUserId,
                occurredAt: $ts,
                snapshot: $snapshot,
            );
        }
    }

    /**
     * Retrieve the event feed for one entity, paginated (newest first).
     *
     * Only returns events at or below $maxLevel so callers can control
     * disclosure depth (0 = operational summary only, 2 = full detail).
     *
     * Guarded like the write paths, for a reason that is not obvious from the
     * signature: the sole caller, ReturnsController::show(), is the redirect
     * target of the settle action. The refund has already committed by the time
     * this runs, so an unguarded failure here shows the operator a 500 on the
     * page that is supposed to confirm the refund — and they will reasonably
     * conclude the money did not move and refund again. Rendering the return
     * with an empty timeline is the lesser evil; the failure is in the log.
     *
     * Outside production this still throws, so a broken feed cannot hide in CI.
     */
    public function feedFor(
        int $shopId,
        string $entityType,
        int $entityId,
        int $maxLevel = 0,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return $this->guarded(
            fn (): LengthAwarePaginator => EntityEvent::withoutTenant()
                ->where('shop_id', $shopId)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('level', '<=', $maxLevel)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate($perPage),
            fallback: new LengthAwarePaginator([], 0, $perPage),
            context:  "failed to read the {$entityType} event feed",
            meta:     [
                'shop_id'     => $shopId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
            ],
        );
    }

    /**
     * Idempotency check: returns true if an identical event row already exists.
     *
     * Observers call this before inserting to avoid duplicate rows when the
     * Eloquent saved hook fires multiple times in the same request.
     *
     * Guarded on the same terms as record(): this runs inside the caller's
     * transaction too, so an unguarded read here is every bit as fatal as an
     * unguarded write. On failure production answers "not recorded" — the
     * worst case is a duplicate audit row, never a lost sale.
     */
    public function alreadyRecorded(
        int $shopId,
        string $entityType,
        int $entityId,
        string $eventType,
        Carbon $occurredAt,
    ): bool {
        return $this->guarded(
            fn (): bool => EntityEvent::withoutTenant()
                ->where('shop_id', $shopId)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('event_type', $eventType)
                ->where('occurred_at', $occurredAt)
                ->exists(),
            fallback: false,
            context:  "failed idempotency check for {$eventType} ({$entityType})",
            meta:     [
                'shop_id'     => $shopId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'event_type'  => $eventType,
            ],
        );
    }
}

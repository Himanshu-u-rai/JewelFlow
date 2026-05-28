<?php

namespace App\Services;

use App\Models\EntityEvent;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class EntityEventService
{
    /**
     * Record an event against a single entity.
     *
     * Low-level method — all writes must go through here; never raw
     * EntityEvent::create() in observers or controllers.
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
    ): EntityEvent {
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
        $event->saveQuietly();

        return $event;
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
     */
    public function feedFor(
        int $shopId,
        string $entityType,
        int $entityId,
        int $maxLevel = 0,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return EntityEvent::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('level', '<=', $maxLevel)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Idempotency check: returns true if an identical event row already exists.
     *
     * Observers call this before inserting to avoid duplicate rows when the
     * Eloquent saved hook fires multiple times in the same request.
     */
    public function alreadyRecorded(
        int $shopId,
        string $entityType,
        int $entityId,
        string $eventType,
        Carbon $occurredAt,
    ): bool {
        return EntityEvent::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('event_type', $eventType)
            ->where('occurred_at', $occurredAt)
            ->exists();
    }
}

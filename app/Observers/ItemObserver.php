<?php

namespace App\Observers;

use App\Models\Item;
use App\Services\EntityEventService;
use Carbon\Carbon;
use Throwable;

class ItemObserver
{
    public function __construct(
        private readonly EntityEventService $entityEventService,
    ) {}

    /**
     * Emit an entity event when an item's status changes.
     *
     * Only Level 0 (operational) events are emitted here — the kind that are
     * immediately meaningful to a shop owner scanning the timeline.
     * alreadyRecorded() guards against duplicates when saved fires multiple times.
     */
    public function saved(Item $item): void
    {
        if (! $item->wasChanged('status')) {
            return;
        }

        $newStatus = $item->status;
        $oldStatus = $item->getOriginal('status');

        $summary = $this->resolveSummary($oldStatus, $newStatus, $item);

        if ($summary === null) {
            // No meaningful event for this transition.
            return;
        }

        $occurredAt = Carbon::now();
        $shopId     = (int) $item->shop_id;
        $actorId    = auth()->id();

        $eventType = 'item_status_changed_to_' . $newStatus;

        if ($this->entityEventService->alreadyRecorded(
            shopId:     $shopId,
            entityType: 'item',
            entityId:   (int) $item->id,
            eventType:  $eventType,
            occurredAt: $occurredAt,
        )) {
            return;
        }

        try {
            $this->entityEventService->record(
                shopId:      $shopId,
                entityType:  'item',
                entityId:    (int) $item->id,
                eventType:   $eventType,
                summary:     $summary,
                level:       0,
                detail:      [
                    'from'    => $oldStatus,
                    'to'      => $newStatus,
                    'barcode' => $item->barcode,
                ],
                actorUserId: $actorId ? (int) $actorId : null,
                occurredAt:  $occurredAt,
            );
        } catch (Throwable $e) {
            \Log::warning('ItemObserver: failed to record status change event for item #' . $item->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Map a status transition to a human-readable summary.
     * Returns null when the transition is not worth surfacing.
     */
    private function resolveSummary(?string $from, ?string $to, Item $item): ?string
    {
        return match (true) {
            $from === 'in_stock' && $to === 'sold'
                => 'Item sold',

            $from === 'in_stock' && $to === 'returned'
                => 'Item returned to shop',

            $from === 'returned' && $to === 'pending_restock'
                => 'Returned item awaiting inspection',

            $from === 'pending_restock' && $to === 'in_stock'
                => 'Item returned to display stock',

            $to === 'melted'
                => 'Item melted — gold recovered to vault',

            $to === 'with_karigar'
                => 'Item sent to karigar for work',

            $to === 'written_off'
                => 'Item written off',

            // Catch-all for any other meaningful status change — keep noise low by
            // only emitting when transitioning away from a known meaningful state.
            $from !== null && $to !== null && $from !== $to
                => null,

            default => null,
        };
    }
}

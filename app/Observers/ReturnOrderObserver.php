<?php

namespace App\Observers;

use App\Models\ReturnOrder;
use App\Services\EntityEventService;
use Throwable;

class ReturnOrderObserver
{
    public function __construct(
        private readonly EntityEventService $entityEventService,
    ) {}

    /**
     * Emit a `return_settled` event when a return order transitions to settled.
     */
    public function saved(ReturnOrder $returnOrder): void
    {
        if (! $returnOrder->wasChanged('status')) {
            return;
        }

        if ($returnOrder->status !== ReturnOrder::STATUS_SETTLED) {
            return;
        }

        $occurredAt = $returnOrder->settled_at ?? $returnOrder->updated_at ?? now();
        $shopId     = (int) $returnOrder->shop_id;
        $actorId    = $returnOrder->settled_by_user_id ?? auth()->id();

        $cn = $returnOrder->creditNote;

        $cnSuffix = '';
        if ($cn && $cn->credit_note_number) {
            $cnSuffix = ' — ' . $cn->credit_note_number . ', ₹' . number_format((float) $cn->total, 2);
        } elseif ($cn && $cn->total) {
            $cnSuffix = ' — ₹' . number_format((float) $cn->total, 2);
        }

        $detail = [
            'return_order_id'    => $returnOrder->id,
            'invoice_id'         => $returnOrder->invoice_id,
            'return_type'        => $returnOrder->return_type,
            'credit_note_id'     => $cn?->id,
            'credit_note_number' => $cn?->credit_note_number,
            'refund_total'       => $cn ? (float) $cn->total : null,
        ];

        // Record against the return_order entity (always, including walk-in returns)
        if (! $this->entityEventService->alreadyRecorded(
            shopId:     $shopId,
            entityType: 'return_order',
            entityId:   (int) $returnOrder->id,
            eventType:  'return_settled',
            occurredAt: $occurredAt,
        )) {
            try {
                $this->entityEventService->record(
                    shopId:      $shopId,
                    entityType:  'return_order',
                    entityId:    (int) $returnOrder->id,
                    eventType:   'return_settled',
                    summary:     'Return settled' . $cnSuffix,
                    level:       0,
                    detail:      $detail,
                    actorUserId: $actorId ? (int) $actorId : null,
                    occurredAt:  $occurredAt,
                    snapshot:    [
                        'return_order_id'    => $returnOrder->id,
                        'credit_note_number' => $cn?->credit_note_number,
                    ],
                );
            } catch (Throwable $e) {
                \Log::warning('EntityEventService: failed to record return_settled (return_order): ' . $e->getMessage());
            }
        }

        // Record against the customer entity (named customers only)
        if (! $returnOrder->customer_id) {
            return;
        }

        if ($this->entityEventService->alreadyRecorded(
            shopId:     $shopId,
            entityType: 'customer',
            entityId:   (int) $returnOrder->customer_id,
            eventType:  'return_settled',
            occurredAt: $occurredAt,
        )) {
            return;
        }

        try {
            $this->entityEventService->record(
                shopId:      $shopId,
                entityType:  'customer',
                entityId:    (int) $returnOrder->customer_id,
                eventType:   'return_settled',
                summary:     'Return order #' . $returnOrder->id . ' settled' . $cnSuffix,
                level:       0,
                detail:      $detail,
                actorUserId: $actorId ? (int) $actorId : null,
                occurredAt:  $occurredAt,
            );
        } catch (Throwable $e) {
            \Log::warning('EntityEventService: failed to record return_settled (customer): ' . $e->getMessage());
        }
    }
}

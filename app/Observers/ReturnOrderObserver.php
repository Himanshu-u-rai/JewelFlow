<?php

namespace App\Observers;

use App\Models\ReturnOrder;
use App\Models\User;
use App\Services\EntityEventService;
use App\Services\Mobile\PushNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReturnOrderObserver
{
    public function __construct(
        private readonly EntityEventService $entityEventService,
        private readonly PushNotificationService $push,
    ) {}

    /**
     * Emit a `return_settled` event when a return order transitions to settled,
     * and push approval requests to owners/managers when one enters
     * pending_approval.
     */
    public function saved(ReturnOrder $returnOrder): void
    {
        if (! $returnOrder->wasChanged('status')) {
            return;
        }

        if ($returnOrder->status === ReturnOrder::STATUS_PENDING_APPROVAL) {
            $this->notifyApprovers($returnOrder);
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

    /**
     * Push a targeted approval request to the shop's owners/managers — only
     * users whose role grants `returns.approve`. The staff member who raised
     * the return is naturally excluded (they lack the permission). Payload
     * carries ids only; the app fetches detail over the authenticated API.
     */
    private function notifyApprovers(ReturnOrder $returnOrder): void
    {
        try {
            $shopId = (int) $returnOrder->shop_id;

            $approverIds = User::where('shop_id', $shopId)
                ->whereHas('role.permissions', fn ($q) => $q->where('name', 'returns.approve'))
                ->pluck('id')
                ->all();

            if (empty($approverIds)) {
                return;
            }

            $this->push->queueToShop(
                $shopId,
                'Approval needed',
                'A return is waiting for your approval.',
                ['type' => 'return_approval', 'return_order_id' => (int) $returnOrder->id],
                $approverIds,
            );
        } catch (Throwable $e) {
            Log::warning('Return approval push failed: ' . $e->getMessage());
        }
    }
}

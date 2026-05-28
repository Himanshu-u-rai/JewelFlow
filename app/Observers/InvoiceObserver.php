<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\EntityEventService;
use Throwable;

class InvoiceObserver
{
    public function __construct(
        private readonly EntityEventService $entityEventService,
    ) {}

    /**
     * Emit a `sale_finalized` event when an invoice transitions to finalized.
     *
     * Guards:
     *   - wasChanged('status') ensures the observer only fires on real state changes.
     *   - alreadyRecorded() is the idempotency gate in case saved fires twice.
     */
    public function saved(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status')) {
            return;
        }

        if ($invoice->status !== Invoice::STATUS_FINALIZED) {
            return;
        }

        $occurredAt = $invoice->finalized_at ?? $invoice->created_at ?? now();
        $shopId     = (int) $invoice->shop_id;
        $actorId    = $invoice->finalized_by ?? auth()->id();

        // Idempotency guard
        if ($this->entityEventService->alreadyRecorded(
            shopId:      $shopId,
            entityType:  'invoice',
            entityId:    (int) $invoice->id,
            eventType:   'sale_finalized',
            occurredAt:  $occurredAt,
        )) {
            return;
        }

        $summary = "Invoice {$invoice->invoice_number} finalised";
        if ($invoice->total) {
            $summary .= ' — ₹' . number_format((float) $invoice->total, 2);
        }

        $detail = [
            'invoice_number' => $invoice->invoice_number,
            'total'          => (float) $invoice->total,
            'subtotal'       => (float) $invoice->subtotal,
            'gst'            => (float) $invoice->gst,
        ];

        // Always record on the invoice entity itself.
        try {
            $this->entityEventService->record(
                shopId:      $shopId,
                entityType:  'invoice',
                entityId:    (int) $invoice->id,
                eventType:   'sale_finalized',
                summary:     $summary,
                level:       0,
                detail:      $detail,
                actorUserId: $actorId ? (int) $actorId : null,
                occurredAt:  $occurredAt,
            );
        } catch (Throwable $e) {
            // Entity events are non-critical — log and continue.
            \Log::warning('EntityEventService: failed to record sale_finalized (invoice entity): ' . $e->getMessage());
        }

        // Also record on the customer entity if there is one.
        if ($invoice->customer_id) {
            try {
                $this->entityEventService->record(
                    shopId:      $shopId,
                    entityType:  'customer',
                    entityId:    (int) $invoice->customer_id,
                    eventType:   'sale_finalized',
                    summary:     $summary,
                    level:       0,
                    detail:      array_merge($detail, ['invoice_id' => $invoice->id]),
                    actorUserId: $actorId ? (int) $actorId : null,
                    occurredAt:  $occurredAt,
                );
            } catch (Throwable $e) {
                \Log::warning('EntityEventService: failed to record sale_finalized (customer entity): ' . $e->getMessage());
            }
        }
    }
}

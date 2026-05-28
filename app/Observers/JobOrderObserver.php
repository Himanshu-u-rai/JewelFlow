<?php

namespace App\Observers;

use App\Models\JobOrder;
use App\Services\EntityEventService;
use Carbon\Carbon;
use Throwable;

class JobOrderObserver
{
    public function __construct(
        private readonly EntityEventService $entityEventService,
    ) {}

    /**
     * Emit entity events when a job order's status changes.
     *
     * Handled transitions:
     *   STATUS_ISSUED    → job_issued    (karigar entity)
     *   STATUS_COMPLETED → job_completed (karigar entity)
     *   STATUS_CANCELLED → job_cancelled (karigar entity)
     */
    public function saved(JobOrder $jobOrder): void
    {
        if (! $jobOrder->wasChanged('status')) {
            return;
        }

        $shopId     = (int) $jobOrder->shop_id;
        $actorId    = $jobOrder->created_by_user_id ?? auth()->id();
        $occurredAt = $jobOrder->updated_at ?? now();

        match ($jobOrder->status) {
            JobOrder::STATUS_ISSUED    => $this->recordJobIssued($jobOrder, $shopId, $actorId, $occurredAt),
            JobOrder::STATUS_COMPLETED => $this->recordJobCompleted($jobOrder, $shopId, $actorId, $occurredAt),
            JobOrder::STATUS_CANCELLED => $this->recordJobCancelled($jobOrder, $shopId, $actorId, $occurredAt),
            default                    => null,
        };
    }

    private function recordJobIssued(JobOrder $jobOrder, int $shopId, ?int $actorId, Carbon $occurredAt): void
    {
        if ($this->entityEventService->alreadyRecorded(
            shopId:     $shopId,
            entityType: 'karigar',
            entityId:   (int) $jobOrder->karigar_id,
            eventType:  'job_issued',
            occurredAt: $occurredAt,
        )) {
            return;
        }

        $summary = "Job {$jobOrder->job_order_number} issued ({$jobOrder->job_type})";
        if ($jobOrder->issued_fine_weight) {
            $summary .= ' — ' . number_format((float) $jobOrder->issued_fine_weight, 3) . 'g fine';
        }

        try {
            $this->entityEventService->record(
                shopId:      $shopId,
                entityType:  'karigar',
                entityId:    (int) $jobOrder->karigar_id,
                eventType:   'job_issued',
                summary:     $summary,
                level:       0,
                detail:      [
                    'job_order_id'       => $jobOrder->id,
                    'job_order_number'   => $jobOrder->job_order_number,
                    'issued_fine_weight' => (float) ($jobOrder->issued_fine_weight ?? 0),
                    'metal_type'         => $jobOrder->metal_type,
                ],
                actorUserId: $actorId ? (int) $actorId : null,
                occurredAt:  $occurredAt,
                snapshot:    [
                    'karigar_name' => $jobOrder->karigar?->name,
                    'job_number'   => $jobOrder->job_order_number,
                ],
            );
        } catch (Throwable $e) {
            \Log::warning('EntityEventService: failed to record job_issued: ' . $e->getMessage());
        }
    }

    private function recordJobCompleted(JobOrder $jobOrder, int $shopId, ?int $actorId, Carbon $occurredAt): void
    {
        // For completions use the dedicated completed_at timestamp when available.
        $completedAt = $jobOrder->completed_at ?? $occurredAt;

        if ($this->entityEventService->alreadyRecorded(
            shopId:     $shopId,
            entityType: 'karigar',
            entityId:   (int) $jobOrder->karigar_id,
            eventType:  'job_completed',
            occurredAt: $completedAt,
        )) {
            return;
        }

        $summary = "Job order {$jobOrder->job_order_number} completed";
        if ($jobOrder->issued_fine_weight) {
            $summary .= ' — ' . number_format((float) $jobOrder->issued_fine_weight, 3) . 'g fine';
        }

        try {
            $this->entityEventService->record(
                shopId:      $shopId,
                entityType:  'karigar',
                entityId:    (int) $jobOrder->karigar_id,
                eventType:   'job_completed',
                summary:     $summary,
                level:       0,
                detail:      [
                    'job_order_id'         => $jobOrder->id,
                    'job_order_number'     => $jobOrder->job_order_number,
                    'metal_type'           => $jobOrder->metal_type,
                    'issued_fine_weight'   => (float) $jobOrder->issued_fine_weight,
                    'returned_fine_weight' => (float) $jobOrder->returned_fine_weight,
                    'actual_wastage_fine'  => (float) $jobOrder->actual_wastage_fine,
                ],
                actorUserId: $actorId ? (int) $actorId : null,
                occurredAt:  $completedAt,
                snapshot:    [
                    'karigar_name' => $jobOrder->karigar?->name,
                    'job_number'   => $jobOrder->job_order_number,
                ],
            );
        } catch (Throwable $e) {
            \Log::warning('EntityEventService: failed to record job_completed: ' . $e->getMessage());
        }
    }

    private function recordJobCancelled(JobOrder $jobOrder, int $shopId, ?int $actorId, Carbon $occurredAt): void
    {
        if ($this->entityEventService->alreadyRecorded(
            shopId:     $shopId,
            entityType: 'karigar',
            entityId:   (int) $jobOrder->karigar_id,
            eventType:  'job_cancelled',
            occurredAt: $occurredAt,
        )) {
            return;
        }

        try {
            $this->entityEventService->record(
                shopId:      $shopId,
                entityType:  'karigar',
                entityId:    (int) $jobOrder->karigar_id,
                eventType:   'job_cancelled',
                summary:     "Job {$jobOrder->job_order_number} cancelled",
                level:       0,
                detail:      [
                    'job_order_id'     => $jobOrder->id,
                    'job_order_number' => $jobOrder->job_order_number,
                ],
                actorUserId: $actorId ? (int) $actorId : null,
                occurredAt:  $occurredAt,
                snapshot:    [
                    'karigar_name' => $jobOrder->karigar?->name,
                    'job_number'   => $jobOrder->job_order_number,
                ],
            );
        } catch (Throwable $e) {
            \Log::warning('EntityEventService: failed to record job_cancelled: ' . $e->getMessage());
        }
    }
}

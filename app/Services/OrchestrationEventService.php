<?php

namespace App\Services;

use App\Models\OrchestrationEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Service for recording and querying Tier 2 orchestration decisions.
 *
 * Every guided suggestion (pre-selected default), its computed reason, and the
 * user's acceptance or override are stored here. This powers the Constitution's
 * Article IX override-rate reporting.
 */
class OrchestrationEventService
{
    /**
     * Record an orchestration decision.
     *
     * Call this whenever a Tier 2 suggestion is accepted or overridden.
     * `was_overridden` is computed automatically from the difference between
     * the suggested and actual decision.
     */
    public function record(
        int $shopId,
        int $userId,
        string $entityType,
        int $entityId,
        string $promptType,
        string $suggestedAction,
        string $suggestionReason,
        string $userDecision,
        ?array $contextData = null,
    ): OrchestrationEvent {
        $event = new OrchestrationEvent();
        $event->forceFill([
            'shop_id'           => $shopId,
            'user_id'           => $userId,
            'entity_type'       => $entityType,
            'entity_id'         => $entityId,
            'prompt_type'       => $promptType,
            'suggested_action'  => $suggestedAction,
            'suggestion_reason' => $suggestionReason,
            'user_decision'     => $userDecision,
            'was_overridden'    => $suggestedAction !== $userDecision,
            'context_data'      => $contextData,
        ]);
        $event->saveQuietly();

        return $event;
    }

    /**
     * Get the override rate for a specific prompt type in a shop.
     *
     * @return array{total: int, overridden: int, rate: float}
     */
    public function overrideStats(int $shopId, string $promptType): array
    {
        $total     = OrchestrationEvent::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('prompt_type', $promptType)
            ->count();

        $overridden = OrchestrationEvent::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('prompt_type', $promptType)
            ->where('was_overridden', true)
            ->count();

        return [
            'total'     => $total,
            'overridden' => $overridden,
            'rate'      => $total > 0 ? round($overridden / $total, 4) : 0.0,
        ];
    }

    /**
     * Per-prompt_type override stats for a given ISO week.
     *
     * @return Collection<int, array{prompt_type: string, total: int, overridden: int, rate: float}>
     */
    public function weeklyOverrideReport(int $shopId, Carbon $weekStart): Collection
    {
        $weekEnd = $weekStart->copy()->endOfWeek();

        $rows = OrchestrationEvent::withoutTenant()
            ->selectRaw('prompt_type, COUNT(*) as total, SUM(CASE WHEN was_overridden THEN 1 ELSE 0 END) as overridden')
            ->where('shop_id', $shopId)
            ->whereBetween('created_at', [$weekStart->startOfDay(), $weekEnd->endOfDay()])
            ->groupBy('prompt_type')
            ->orderBy('prompt_type')
            ->get();

        return $rows->map(fn ($row) => [
            'prompt_type' => $row->prompt_type,
            'total'       => (int) $row->total,
            'overridden'  => (int) $row->overridden,
            'rate'        => $row->total > 0 ? round($row->overridden / $row->total, 4) : 0.0,
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\StoneComponent;
use App\Models\StoneRevaluationEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 2B — Stone revaluation orchestrator.
 *
 * The ONLY legitimate entry point for changing a stone's unit_value or
 * count post-creation. Every call:
 *   1. Validates the stone is still mutable (not yet snapshotted to a
 *      finalized invoice_item or settled return_line_item).
 *   2. Validates the parent item is in_stock (revaluation of a stone
 *      attached to a sold/returned/transferred item is forbidden).
 *   3. Writes one row to stone_revaluation_events with before/after.
 *   4. Updates stone_components.unit_value and stone_components.total_value
 *      atomically in the same transaction.
 *   5. Writes an AuditLog entry tagged with the operator.
 *
 * Constitutional alignment:
 *   - Article XIV (Manual Valuation Boundary): every revaluation is
 *     operator-initiated. NO automated process may call this service.
 *     Background jobs, schedulers, controllers acting outside an
 *     authenticated owner/manager session — all forbidden.
 *   - Article I (Append-only): each revaluation produces a new
 *     stone_revaluation_events row; prior rows never modified.
 *   - Snapshot doctrine: revaluation is rejected once the parent
 *     stone is snapshotted on a sale or return.
 *
 * Service is final-class to discourage subclassing that might bypass
 * the discipline above.
 */
final class StoneRevaluationService
{
    /**
     * Revalue a stone in inventory.
     *
     * @param  StoneComponent  $stone        Target stone (must be inventory-attached, not snapshotted)
     * @param  float           $newUnitValue New per-stone value in rupees
     * @param  int             $newCount     New count (>= 1)
     * @param  string          $reason       Mandatory operator justification (min 5 chars)
     * @param  int             $userId       Operator's user_id (must be a real authenticated user)
     * @return StoneRevaluationEvent The newly-recorded event row
     *
     * @throws LogicException if the stone is snapshotted, the item is
     *                        not in_stock, the reason is empty, or
     *                        the new values are invalid.
     */
    public function revalue(
        StoneComponent $stone,
        float $newUnitValue,
        int $newCount,
        string $reason,
        int $userId
    ): StoneRevaluationEvent {
        // Pre-flight: input sanity.
        if ($newUnitValue < 0) {
            throw new LogicException('Stone revaluation: unit value cannot be negative.');
        }
        if ($newCount < 1) {
            throw new LogicException('Stone revaluation: count must be at least 1.');
        }
        if (trim($reason) === '' || mb_strlen(trim($reason)) < 5) {
            throw new LogicException('Stone revaluation: reason is required (min 5 characters).');
        }
        if ($userId <= 0) {
            throw new LogicException('Stone revaluation: a real authenticated user_id is required.');
        }

        // Pre-flight: snapshot check.
        if ($stone->invoice_item_id !== null || $stone->return_line_item_id !== null) {
            throw new LogicException(
                'Stone revaluation forbidden: this stone is snapshotted to a sale or return. '
                . 'Snapshotted stones are immutable per Article I.'
            );
        }

        // Pre-flight: item must be in stock.
        if ($stone->item_id === null) {
            throw new LogicException(
                'Stone revaluation forbidden: stone is not attached to an inventory item.'
            );
        }
        $item = $stone->item; // eager relation
        if (! $item || $item->status !== 'in_stock') {
            throw new LogicException(
                'Stone revaluation forbidden: parent item status is "'
                . ($item?->status ?? 'unknown') . '", must be "in_stock".'
            );
        }

        // Pre-flight: no-op detection. If nothing actually changes,
        // raise rather than write a meaningless audit row.
        $oldUnitValue  = (float) $stone->unit_value;
        $oldCount      = (int) $stone->count;
        $oldTotalValue = round($oldUnitValue * $oldCount, 2);
        $newTotalValue = round($newUnitValue * $newCount, 2);

        if (
            abs($oldUnitValue - $newUnitValue) < 0.005
            && $oldCount === $newCount
        ) {
            throw new LogicException(
                'Stone revaluation: new values match current values. Nothing to record.'
            );
        }

        return DB::transaction(function () use (
            $stone, $newUnitValue, $newCount, $newTotalValue,
            $oldUnitValue, $oldCount, $oldTotalValue, $reason, $userId
        ) {
            $delta = round($newTotalValue - $oldTotalValue, 2);

            // 1. Write the event ledger row FIRST. If anything fails
            //    after this, the transaction rolls back the whole thing.
            $event = StoneRevaluationEvent::record([
                'shop_id'                => (int) $stone->shop_id,
                'stone_component_id'     => (int) $stone->id,
                'old_unit_value'         => $oldUnitValue,
                'old_count'              => $oldCount,
                'old_total_value'        => $oldTotalValue,
                'new_unit_value'         => round($newUnitValue, 2),
                'new_count'              => $newCount,
                'new_total_value'        => $newTotalValue,
                'delta_total_value'      => $delta,
                'reason'                 => trim($reason),
                'reevaluated_by_user_id' => $userId,
            ]);

            // 2. Update the stone_components row. The snapshot guard
            //    permits this because the stone is not snapshotted —
            //    pre-flight already verified that. The CHECK constraint
            //    on stone_components requires unit_value * count == total_value,
            //    which we satisfy by computing total_value from new inputs.
            $stone->update([
                'unit_value'  => round($newUnitValue, 2),
                'count'       => $newCount,
                'total_value' => $newTotalValue,
            ]);

            // 3. AuditLog row for non-financial audit visibility.
            try {
                AuditLog::create([
                    'shop_id'     => (int) $stone->shop_id,
                    'user_id'     => $userId,
                    'action'      => 'stone_revalued',
                    'model_type'  => 'stone_component',
                    'model_id'    => (int) $stone->id,
                    'description' => sprintf(
                        'Stone #%d revalued: %d × ₹%s → %d × ₹%s (Δ₹%s). Reason: %s',
                        $stone->id,
                        $oldCount, number_format($oldUnitValue, 2),
                        $newCount, number_format($newUnitValue, 2),
                        number_format($delta, 2),
                        $reason
                    ),
                    'data' => [
                        'event_id'         => (int) $event->id,
                        'old_unit_value'   => $oldUnitValue,
                        'old_count'        => $oldCount,
                        'new_unit_value'   => round($newUnitValue, 2),
                        'new_count'        => $newCount,
                        'delta'            => $delta,
                        'reason'           => trim($reason),
                    ],
                ]);
            } catch (\Throwable $e) {
                // AuditLog failure should not roll back the revaluation —
                // the ledger event is the authoritative record. Log the
                // gap to PHP error log so it can be recovered.
                error_log('[StoneRevaluationService] AuditLog write failed: ' . $e->getMessage());
            }

            return $event;
        });
    }
}

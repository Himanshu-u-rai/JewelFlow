<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\JobOrder;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class BullionVaultService
{
    /**
     * Sanctioned manual vault correction — the compensating-entry path for a
     * physical-vs-system fine-weight variance (the Recovery runbook depends on
     * this existing). It NEVER edits past movements; it appends a single
     * `vault_adjustment` MetalMovement and moves the lot's remaining fine weight
     * accordingly. A signed delta (+ adds, − removes) plus a mandatory reason.
     */
    public function adjustLot(MetalLot $lot, float $deltaFine, string $reason, int $userId): MetalMovement
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new LogicException('A reason is required for a vault adjustment.');
        }
        $deltaFine = round($deltaFine, 6);
        if (abs($deltaFine) < 0.000001) {
            throw new LogicException('Adjustment amount must be non-zero.');
        }

        return DB::transaction(function () use ($lot, $deltaFine, $reason, $userId) {
            $locked = MetalLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $newRemaining = round((float) $locked->fine_weight_remaining + $deltaFine, 6);
            if ($newRemaining < -0.000001) {
                throw new LogicException('Adjustment would make the lot balance negative ('
                    . number_format($newRemaining, 4) . 'g). Reduce the amount.');
            }

            $movement = MetalMovement::record([
                'shop_id'        => $locked->shop_id,
                'from_lot_id'    => $deltaFine < 0 ? $locked->id : null,
                'to_lot_id'      => $deltaFine > 0 ? $locked->id : null,
                'fine_weight'    => abs($deltaFine),
                'type'           => 'vault_adjustment',
                'reference_type'        => 'metal_lot',
                'reference_id'          => $locked->id,
                'user_id'               => $userId,
                'adjustment_reason'     => $reason,
                'adjustment_approved_by' => $userId,
            ]);

            $locked->fine_weight_remaining = max(0, $newRemaining);
            $locked->save();

            AuditLog::create([
                'shop_id'     => $locked->shop_id,
                'user_id'     => $userId,
                'action'      => 'vault_adjusted',
                'model_type'  => 'metal_lot',
                'model_id'    => $locked->id,
                'description' => 'Vault manual adjustment of ' . number_format($deltaFine, 4)
                    . 'g fine on lot #' . $locked->lot_number . '. Reason: ' . $reason,
                'data'        => [
                    'delta_fine'      => $deltaFine,
                    'new_remaining'   => (float) $locked->fine_weight_remaining,
                    'movement_id'     => $movement->id,
                    'reason'          => $reason,
                ],
            ]);

            return $movement;
        });
    }

    /**
     * Vault balances grouped by purity, with in-vault and with-karigar fine totals.
     *
     * @return Collection<int, array{purity: float, in_vault_fine: float, with_karigar_fine: float, total_fine: float, lots_count: int}>
     */
    public function vaultBalances(int $shopId): Collection
    {
        $lots = MetalLot::query()
            ->where('shop_id', $shopId)
            ->get(['id', 'metal_type', 'purity', 'fine_weight_remaining', 'source']);

        // Key by (metal_type, purity) so different metals at the same purity
        // are NEVER merged. Merging gold and silver that happen to share a
        // purity figure would silently corrupt per-metal balances — see
        // CONSTITUTION.md Article XIII/XIV. Legacy lots with a NULL metal_type
        // group under an 'unknown' bucket rather than colliding with a real metal.
        $keyFor = fn ($metalType, $purity) => ($metalType ?? 'unknown') . '|' . (string) (float) $purity;

        // karigar_held lots are physically WITH a karigar, not in the vault, so
        // they feed with_karigar_fine — never in_vault_fine. Every other lot is
        // physical vault stock. (No-op until held lots exist.)
        $byKey = $lots->groupBy(fn ($l) => $keyFor($l->metal_type, $l->purity))
            ->map(function ($group) {
                $first    = $group->first();
                $vaultLots = $group->where('source', '<>', 'karigar_held');
                return [
                    'metal_type'        => $first->metal_type,
                    'purity'            => (float) $first->purity,
                    'in_vault_fine'     => (float) $vaultLots->sum('fine_weight_remaining'),
                    'with_karigar_fine' => (float) $group->where('source', 'karigar_held')->sum('fine_weight_remaining'),
                    'total_fine'        => 0.0,
                    'lots_count'        => $vaultLots->count(),
                ];
            });

        $openJobs = JobOrder::query()
            ->where('shop_id', $shopId)
            ->whereIn('status', [
                JobOrder::STATUS_ISSUED,
                JobOrder::STATUS_PARTIAL_RETURN,
            ])
            ->get(['metal_type', 'purity', 'issued_fine_weight', 'returned_fine_weight', 'leftover_returned_fine_weight', 'actual_wastage_fine']);

        foreach ($openJobs as $job) {
            $key = $keyFor($job->metal_type, $job->purity);
            $outstanding = max(
                0.0,
                (float) $job->issued_fine_weight
                    - (float) $job->returned_fine_weight
                    - (float) $job->leftover_returned_fine_weight
                    - (float) $job->actual_wastage_fine
            );

            if ($byKey->has($key)) {
                $row = $byKey->get($key);
                $row['with_karigar_fine'] += $outstanding;
                $byKey->put($key, $row);
            } else {
                $byKey->put($key, [
                    'metal_type'        => $job->metal_type,
                    'purity'            => (float) $job->purity,
                    'in_vault_fine'     => 0.0,
                    'with_karigar_fine' => $outstanding,
                    'total_fine'        => 0.0,
                    'lots_count'        => 0,
                ]);
            }
        }

        return $byKey
            ->map(function ($row) {
                $row['total_fine'] = $row['in_vault_fine'] + $row['with_karigar_fine'];
                return $row;
            })
            ->sortKeysDesc()
            ->values();
    }

    public function withKarigarFine(int $shopId, ?int $karigarId = null): float
    {
        $query = JobOrder::query()
            ->where('shop_id', $shopId)
            ->whereIn('status', [
                JobOrder::STATUS_ISSUED,
                JobOrder::STATUS_PARTIAL_RETURN,
            ]);

        if ($karigarId) {
            $query->where('karigar_id', $karigarId);
        }

        $openOutstanding = (float) $query->get()->sum(function ($j) {
            return max(
                0.0,
                (float) $j->issued_fine_weight
                    - (float) $j->returned_fine_weight
                    - (float) $j->leftover_returned_fine_weight
                    - (float) $j->actual_wastage_fine
            );
        });

        // Plus any metal physically retained by the karigar (karigar_held lots),
        // which persists across job completion. Disjoint from open-job
        // outstanding above. (No-op until held lots exist.)
        $heldQuery = MetalLot::query()
            ->where('shop_id', $shopId)
            ->where('source', 'karigar_held');

        if ($karigarId) {
            $heldQuery->where('karigar_id', $karigarId);
        }

        return $openOutstanding + (float) $heldQuery->sum('fine_weight_remaining');
    }

    public function recentLedger(int $shopId, int $limit = 50)
    {
        return MetalMovement::query()
            ->where('shop_id', $shopId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}

<?php

namespace App\Services;

use App\Models\JobOrder;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use Illuminate\Support\Collection;

class BullionVaultService
{
    /**
     * Vault balances grouped by purity, with in-vault and with-karigar fine totals.
     *
     * @return Collection<int, array{purity: float, in_vault_fine: float, with_karigar_fine: float, total_fine: float, lots_count: int}>
     */
    public function vaultBalances(int $shopId): Collection
    {
        $lots = MetalLot::query()
            ->where('shop_id', $shopId)
            ->get(['id', 'metal_type', 'purity', 'fine_weight_remaining']);

        // Key by (metal_type, purity) so different metals at the same purity
        // are NEVER merged. Merging gold and silver that happen to share a
        // purity figure would silently corrupt per-metal balances — see
        // CONSTITUTION.md Article XIII/XIV. Legacy lots with a NULL metal_type
        // group under an 'unknown' bucket rather than colliding with a real metal.
        $keyFor = fn ($metalType, $purity) => ($metalType ?? 'unknown') . '|' . (string) (float) $purity;

        $byKey = $lots->groupBy(fn ($l) => $keyFor($l->metal_type, $l->purity))
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'metal_type'        => $first->metal_type,
                    'purity'            => (float) $first->purity,
                    'in_vault_fine'     => (float) $group->sum('fine_weight_remaining'),
                    'with_karigar_fine' => 0.0,
                    'total_fine'        => 0.0,
                    'lots_count'        => $group->count(),
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

        return (float) $query->get()->sum(function ($j) {
            return max(
                0.0,
                (float) $j->issued_fine_weight
                    - (float) $j->returned_fine_weight
                    - (float) $j->leftover_returned_fine_weight
                    - (float) $j->actual_wastage_fine
            );
        });
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

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
            ->get(['id', 'purity', 'fine_weight_remaining']);

        // FIX #15: key by purity string for O(1) lookups instead of O(n) firstWhere scans
        $byPurity = $lots->groupBy(fn ($l) => (string) (float) $l->purity)
            ->map(fn ($group, $purityKey) => [
                'purity'            => (float) $purityKey,
                'in_vault_fine'     => (float) $group->sum('fine_weight_remaining'),
                'with_karigar_fine' => 0.0,
                'total_fine'        => 0.0,
                'lots_count'        => $group->count(),
            ]);

        $openJobs = JobOrder::query()
            ->where('shop_id', $shopId)
            ->whereIn('status', [
                JobOrder::STATUS_ISSUED,
                JobOrder::STATUS_PARTIAL_RETURN,
            ])
            ->get(['purity', 'issued_fine_weight', 'returned_fine_weight', 'leftover_returned_fine_weight', 'actual_wastage_fine']);

        foreach ($openJobs as $job) {
            $key = (string) (float) $job->purity;
            $outstanding = max(
                0.0,
                (float) $job->issued_fine_weight
                    - (float) $job->returned_fine_weight
                    - (float) $job->leftover_returned_fine_weight
                    - (float) $job->actual_wastage_fine
            );

            if ($byPurity->has($key)) {
                $row = $byPurity->get($key);
                $row['with_karigar_fine'] += $outstanding;
                $byPurity->put($key, $row);
            } else {
                $byPurity->put($key, [
                    'purity'            => (float) $key,
                    'in_vault_fine'     => 0.0,
                    'with_karigar_fine' => $outstanding,
                    'total_fine'        => 0.0,
                    'lots_count'        => 0,
                ]);
            }
        }

        return $byPurity
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

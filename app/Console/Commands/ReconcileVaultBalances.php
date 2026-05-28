<?php

namespace App\Console\Commands;

use App\Models\AcknowledgedVaultDiscrepancy;
use App\Models\VaultReconciliationRun;
use App\Services\AccountingAuditService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileVaultBalances extends Command
{
    protected $signature = 'vault:reconcile
                            {--shop= : Scope reconciliation to one shop ID (omit for all active shops)}
                            {--acknowledge : Record the current discrepancies as reviewed-and-accepted (requires --reason)}
                            {--reason= : Reason text stored with an acknowledgement}';

    protected $description = 'Verify MetalLot balances against MetalMovement ledger. Report-only — never auto-corrects. Previously-acknowledged discrepancies are reported as known and do not fail the run.';

    // Tolerance matches JobOrderService::TOLERANCE_FINE_GRAMS and the plan §H decision.
    private const TOLERANCE = 0.001;

    public function handle(): int
    {
        $shopFilter = $this->option('shop');
        $shopIds    = $shopFilter !== null
            ? [(int) $shopFilter]
            : DB::table('shops')->whereRaw('is_active = TRUE')->pluck('id')->all();

        if (empty($shopIds)) {
            $this->error('No active shops found.');
            return 1;
        }

        if ($this->option('acknowledge') && ! $this->option('reason')) {
            $this->error('--acknowledge requires --reason="why these are accepted".');
            return 1;
        }

        $totalDiscrepancies = 0;

        foreach ($shopIds as $shopId) {
            $count = $this->reconcileShop((int) $shopId);
            $totalDiscrepancies += $count;
        }

        $this->newLine();

        if ($totalDiscrepancies === 0) {
            $this->info('All lots balanced. No discrepancies found.');
            return 0;
        }

        $this->warn("{$totalDiscrepancies} discrepancy(ies) detected. Review vault_reconciliation_runs for details.");
        return 1;
    }

    private function reconcileShop(int $shopId): int
    {
        // Aggregate MetalMovement credits/debits per lot and compare against
        // the stored fine_weight_remaining balance. Tolerance: 0.001g (see §H).
        //
        // Phase 0: the per-lot reconciliation already correlates each lot
        // to its own movements via FK, so the gram-balance check is
        // metal-correct by construction (no cross-metal contamination).
        // We additionally surface a per-metal SUMMARY at the top of the
        // report so the operator sees vault totals broken down by metal.
        $discrepancies = DB::select("
            SELECT
                ml.id,
                ml.lot_number,
                COALESCE(ml.metal_type, 'unknown')                                     AS metal_type,
                ml.fine_weight_remaining                                               AS stored,
                (
                    COALESCE(SUM(CASE WHEN mm.to_lot_id   = ml.id THEN mm.fine_weight ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN mm.from_lot_id = ml.id THEN mm.fine_weight ELSE 0 END), 0)
                )                                                                      AS ledger_balance,
                ml.fine_weight_remaining - (
                    COALESCE(SUM(CASE WHEN mm.to_lot_id   = ml.id THEN mm.fine_weight ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN mm.from_lot_id = ml.id THEN mm.fine_weight ELSE 0 END), 0)
                )                                                                      AS discrepancy
            FROM metal_lots ml
            LEFT JOIN metal_movements mm
                ON (mm.from_lot_id = ml.id OR mm.to_lot_id = ml.id)
               AND mm.shop_id = ml.shop_id
            WHERE ml.shop_id = ?
            GROUP BY ml.id
            HAVING ABS(
                ml.fine_weight_remaining - (
                    COALESCE(SUM(CASE WHEN mm.to_lot_id   = ml.id THEN mm.fine_weight ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN mm.from_lot_id = ml.id THEN mm.fine_weight ELSE 0 END), 0)
                )
            ) > ?
            ORDER BY ml.id
        ", [$shopId, self::TOLERANCE]);

        // Per-metal vault summary (NEW in Phase 0). Reported regardless of
        // whether discrepancies exist — gives the operator the same view the
        // BullionVaultService now produces, in CLI form.
        $perMetalSummary = DB::select("
            SELECT
                COALESCE(metal_type, 'unknown') AS metal_type,
                COUNT(*)                         AS lots_count,
                SUM(fine_weight_remaining)       AS total_fine
            FROM metal_lots
            WHERE shop_id = ?
            GROUP BY metal_type
            ORDER BY metal_type
        ", [$shopId]);

        if (! empty($perMetalSummary)) {
            $this->line("  Shop {$shopId} per-metal vault summary:");
            $this->table(
                ['Metal', 'Lots', 'Total Fine (g)'],
                array_map(fn ($r) => [
                    $r->metal_type,
                    (int) $r->lots_count,
                    number_format((float) $r->total_fine, 4),
                ], $perMetalSummary)
            );
        }

        $status = empty($discrepancies)
            ? VaultReconciliationRun::STATUS_CLEAN
            : VaultReconciliationRun::STATUS_DISCREPANCY_FOUND;

        $run = VaultReconciliationRun::create([
            'shop_id'          => $shopId,
            'run_at'           => now(),
            'run_by'           => null,
            'status'           => $status,
            'discrepancy_lots' => empty($discrepancies) ? null : array_map(fn ($d) => [
                'lot_id'     => $d->id,
                'lot_number' => $d->lot_number,
                'metal_type' => $d->metal_type,
                'stored'     => (float) $d->stored,
                'ledger'     => (float) $d->ledger_balance,
                'delta'      => (float) $d->discrepancy,
            ], $discrepancies),
        ]);

        if (empty($discrepancies)) {
            $this->line("  Shop {$shopId}: <info>all lots balanced</info>");
            return 0;
        }

        // Acknowledgement awareness. A discrepancy set that exactly matches a
        // previously-acknowledged one (same lots + deltas) is "known" and does
        // not fail the run. Operators record acknowledgements with --acknowledge.
        $signature   = $this->signatureOfCurrent($discrepancies);
        $acknowledged = $this->isSignatureAcknowledged($shopId, $signature);

        if (! $acknowledged && $this->option('acknowledge')) {
            $this->acknowledgeRun($shopId, $run, $discrepancies);
            $acknowledged = true;
            $this->info("  Shop {$shopId}: " . count($discrepancies) . ' discrepancy(ies) acknowledged — run #' . $run->id);
        }

        TenantContext::runFor($shopId, fn () => AccountingAuditService::log([
            'shop_id'     => $shopId,
            'action'      => $acknowledged ? 'vault_discrepancy_acknowledged_seen' : 'vault_discrepancy_detected',
            'model_type'  => 'vault_reconciliation_run',
            'model_id'    => $run->id,
            'description' => count($discrepancies) . ' lot(s) with balance discrepancy '
                . ($acknowledged ? 'seen (known/acknowledged)' : 'detected') . ' (run #' . $run->id . ')',
            'data'        => ['run_id' => $run->id, 'lot_count' => count($discrepancies), 'acknowledged' => $acknowledged],
        ]));

        if ($acknowledged) {
            $this->line("  Shop {$shopId}: " . count($discrepancies) . ' known/acknowledged discrepancy(ies) — not flagged (run #' . $run->id . ')');
            return 0;
        }

        $this->warn("  Shop {$shopId}: " . count($discrepancies) . ' discrepancy(ies) found — run #' . $run->id);
        $this->table(
            ['Lot ID', 'Lot #', 'Metal', 'Stored (g)', 'Ledger (g)', 'Delta (g)'],
            array_map(fn ($d) => [
                $d->id,
                $d->lot_number,
                $d->metal_type,
                number_format((float) $d->stored, 4),
                number_format((float) $d->ledger_balance, 4),
                number_format((float) $d->discrepancy, 4),
            ], $discrepancies)
        );

        return count($discrepancies);
    }

    /**
     * Stable signature of a discrepancy set: sorted "lotId:delta(4dp)" pairs.
     */
    private function signatureOfCurrent(array $discrepancies): string
    {
        $parts = array_map(
            fn ($d) => $d->id . ':' . number_format((float) $d->discrepancy, 4, '.', ''),
            $discrepancies
        );
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * True when an acknowledged reconciliation run for this shop has an
     * identical discrepancy signature (same lots + deltas).
     */
    private function isSignatureAcknowledged(int $shopId, string $signature): bool
    {
        $ackedRunLots = DB::table('acknowledged_vault_discrepancies as a')
            ->join('vault_reconciliation_runs as r', 'r.id', '=', 'a.reconciliation_run_id')
            ->where('a.shop_id', $shopId)
            ->pluck('r.discrepancy_lots');

        foreach ($ackedRunLots as $json) {
            if ($json === null) {
                continue;
            }
            $lots = is_string($json) ? json_decode($json, true) : $json;
            if (! is_array($lots)) {
                continue;
            }
            $parts = array_map(
                fn ($l) => $l['lot_id'] . ':' . number_format((float) $l['delta'], 4, '.', ''),
                $lots
            );
            sort($parts);
            if (implode('|', $parts) === $signature) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record an acknowledgement for the given run. Append-only; no ledger writes.
     */
    private function acknowledgeRun(int $shopId, VaultReconciliationRun $run, array $discrepancies): void
    {
        $userId = DB::table('users')->where('shop_id', $shopId)->orderBy('id')->value('id');
        $total  = array_sum(array_map(fn ($d) => abs((float) $d->discrepancy), $discrepancies));

        TenantContext::runFor($shopId, fn () => AcknowledgedVaultDiscrepancy::create([
            'shop_id'                   => $shopId,
            'reconciliation_run_id'     => $run->id,
            'acknowledged_by_user_id'   => $userId,
            'acknowledged_at'           => now(),
            'acknowledgement_reason'    => (string) $this->option('reason'),
            'discrepancy_amount_at_ack' => round($total, 4),
        ]));
    }
}

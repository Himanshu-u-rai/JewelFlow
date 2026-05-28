<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 2/9 — REVISED for constitutional fidelity.
 *
 * ORIGINAL INTENT: UPDATE all existing metal_movements rows to set
 *   metal_type from their joined metal_lots.
 *
 * WHY THAT INTENT WAS WRONG: metal_movements has been protected by the
 *   `metal_movements_immutable_trigger` since 2026_02_18. That trigger
 *   enforces append-only by raising on every UPDATE. The original
 *   backfill UPDATE statement is constitutionally illegal — it is
 *   exactly the pattern CONSTITUTION.md §2 Pattern F1 forbids and §3
 *   Lane 3 (designated backfill before trigger install) does NOT apply
 *   to (because the trigger predates Phase 0 by months).
 *
 * REVISED BEHAVIOR (this migration now):
 *   - DOES NOT issue any UPDATE.
 *   - Records a forensic count of how many historical rows have
 *     metal_type IS NULL. These rows are constitutionally immutable;
 *     they will remain NULL forever.
 *   - Phase 0 forward-fills via MetalMovement::record() which derives
 *     metal_type from joined lots at INSERT time. New movements carry
 *     their metal identity going forward.
 *   - Aggregation queries (ClosingController, PnlController,
 *     ReconcileVaultBalances) use COALESCE(mm.metal_type, ml.metal_type)
 *     to derive metal identity for legacy rows via the lot FK join.
 *
 * This is the ONLY constitutionally honest path. Bypassing the
 * immutable trigger would violate Article I and Article IX.B.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metal_movements') || ! Schema::hasColumn('metal_movements', 'metal_type')) {
            return;
        }

        // Forensic report only. No DML.
        $report = DB::selectOne(<<<'SQL'
            SELECT
                COUNT(*) AS total_rows,
                COUNT(*) FILTER (WHERE metal_type IS NULL) AS legacy_unfilled,
                COUNT(*) FILTER (
                    WHERE metal_type IS NULL
                      AND (
                          (to_lot_id IS NOT NULL AND EXISTS (
                              SELECT 1 FROM metal_lots ml WHERE ml.id = metal_movements.to_lot_id AND ml.metal_type IS NOT NULL
                          ))
                          OR
                          (from_lot_id IS NOT NULL AND EXISTS (
                              SELECT 1 FROM metal_lots ml WHERE ml.id = metal_movements.from_lot_id AND ml.metal_type IS NOT NULL
                          ))
                      )
                ) AS resolvable_via_join,
                COUNT(*) FILTER (
                    WHERE metal_type IS NULL
                      AND (to_lot_id IS NULL OR NOT EXISTS (
                          SELECT 1 FROM metal_lots ml WHERE ml.id = metal_movements.to_lot_id AND ml.metal_type IS NOT NULL
                      ))
                      AND (from_lot_id IS NULL OR NOT EXISTS (
                          SELECT 1 FROM metal_lots ml WHERE ml.id = metal_movements.from_lot_id AND ml.metal_type IS NOT NULL
                      ))
                ) AS unresolvable
            FROM metal_movements
        SQL);

        if ($report) {
            $msg = sprintf(
                '[Phase0/020000] metal_movements forensic: total=%d, legacy_unfilled=%d, resolvable_via_join=%d, unresolvable=%d. '
                . 'Legacy rows remain NULL (constitutionally immutable). Aggregations use COALESCE with joined lot metal_type.',
                (int) $report->total_rows,
                (int) $report->legacy_unfilled,
                (int) $report->resolvable_via_join,
                (int) $report->unresolvable
            );
            error_log($msg);

            // Also expose to the artisan output. Not fatal — this is informational.
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "  ↳ " . $msg . "\n");
            }
        }
    }

    public function down(): void
    {
        // No-op. The forward migration writes no data; nothing to reverse.
    }
};
